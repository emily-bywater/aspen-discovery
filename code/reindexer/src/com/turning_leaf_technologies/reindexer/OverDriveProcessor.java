package com.turning_leaf_technologies.reindexer;

import org.apache.logging.log4j.Logger;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.Date;
import java.util.HashMap;
import java.util.HashSet;

/**
 * Description goes here
 * Pika
 * User: Mark Noble
 * Date: 12/9/13
 * Time: 9:14 AM
 */
class OverDriveProcessor {
	private GroupedWorkIndexer indexer;
	private Logger logger;
	private PreparedStatement getProductInfoStmt;
	private PreparedStatement getNumCopiesStmt;
	private PreparedStatement getProductMetadataStmt;
	private PreparedStatement getProductAvailabilityStmt;
	private PreparedStatement getProductFormatsStmt;

	OverDriveProcessor(GroupedWorkIndexer groupedWorkIndexer, Connection dbConn, Logger logger) {
		this.indexer = groupedWorkIndexer;
		this.logger = logger;
		try {
			getProductInfoStmt = dbConn.prepareStatement("SELECT * from overdrive_api_products where overdriveId = ?", ResultSet.TYPE_FORWARD_ONLY,  ResultSet.CONCUR_READ_ONLY);
			getNumCopiesStmt = dbConn.prepareStatement("SELECT sum(copiesOwned) as totalOwned FROM overdrive_api_product_availability WHERE productId = ?", ResultSet.TYPE_FORWARD_ONLY,  ResultSet.CONCUR_READ_ONLY);
			getProductMetadataStmt = dbConn.prepareStatement("SELECT * from overdrive_api_product_metadata where productId = ?", ResultSet.TYPE_FORWARD_ONLY,  ResultSet.CONCUR_READ_ONLY);
			getProductAvailabilityStmt = dbConn.prepareStatement("SELECT * from overdrive_api_product_availability where productId = ?", ResultSet.TYPE_FORWARD_ONLY,  ResultSet.CONCUR_READ_ONLY);
			getProductFormatsStmt = dbConn.prepareStatement("SELECT * from overdrive_api_product_formats where productId = ?", ResultSet.TYPE_FORWARD_ONLY,  ResultSet.CONCUR_READ_ONLY);
		} catch (SQLException e) {
			logger.error("Error setting up overdrive processor", e);
		}
	}

	void processRecord(GroupedWorkSolr groupedWork, String identifier) {
		try {
			getProductInfoStmt.setString(1, identifier);
			ResultSet productRS = getProductInfoStmt.executeQuery();
			if (productRS.next()) {
				//Make sure the record isn't deleted
				long productId = productRS.getLong("id");
				String title = productRS.getString("title");

				if (productRS.getInt("deleted") == 1) {
					logger.info("Not processing deleted overdrive product " + title + " - " + identifier);
					indexer.overDriveRecordsSkipped.add(identifier);

				} else {
					getNumCopiesStmt.setLong(1, productId);
					ResultSet numCopiesRS = getNumCopiesStmt.executeQuery();
					numCopiesRS.next();
					if (numCopiesRS.getInt("totalOwned") == 0) {
						logger.debug("Not processing overdrive product with no copies owned" + title + " - " + identifier);
						indexer.overDriveRecordsSkipped.add(identifier);
						return;
					} else {

						RecordInfo overDriveRecord = groupedWork.addRelatedRecord("overdrive", identifier);
						overDriveRecord.setRecordIdentifier("overdrive", identifier);

						String subtitle = productRS.getString("subtitle");
						String series = productRS.getString("series");
						if (subtitle == null) {
							subtitle = "";
						}
						String mediaType = productRS.getString("mediaType");
						String formatCategory;
						String primaryFormat;
						switch (mediaType) {
							case "Audiobook":
								formatCategory = "Audio Books";
								primaryFormat = "eAudiobook";
								break;
							case "Video":
								formatCategory = "Movies";
								primaryFormat = "eVideo";
								break;
							default:
								formatCategory = mediaType;
								primaryFormat = mediaType;
								break;
						}

						HashMap<String, String> metadata = loadOverDriveMetadata(groupedWork, productId, primaryFormat);

						//Decode JSON data to get a little more information
						JSONObject rawMetadataDecoded = null;
						try {
							rawMetadataDecoded = new JSONObject(metadata.get("rawMetadata"));
						} catch (JSONException e) {
							logger.error("Error loading raw data for OverDrive MetaData");
						}

						String fullTitle = title + " " + subtitle;
						fullTitle = fullTitle.trim();
						groupedWork.setTitle(title, title, metadata.get("sortTitle"), primaryFormat);
						groupedWork.setSubTitle(subtitle);
						groupedWork.addFullTitle(fullTitle);

						if (series != null && series.length() > 0) {
							groupedWork.addSeries(series);
							groupedWork.addSeriesWithVolume(series);
						}
						groupedWork.setAuthor(productRS.getString("primaryCreatorName"));
						groupedWork.setAuthAuthor(productRS.getString("primaryCreatorName"));
						groupedWork.setAuthorDisplay(productRS.getString("primaryCreatorName"));

						Date dateAdded = new Date(productRS.getLong("dateAdded") * 1000);

						productRS.close();

						String primaryLanguage = "English";
						String targetAudience = "Adult";
						if (rawMetadataDecoded != null) {
							primaryLanguage = loadOverDriveLanguages(groupedWork, rawMetadataDecoded, identifier);
							targetAudience = loadOverDriveSubjects(groupedWork, rawMetadataDecoded);
						}

						//Load the formats for the record.  For OverDrive, we will create a separate item for each format.
						HashSet<String> validFormats = loadOverDriveFormats(productId, identifier);
						String detailedFormats = Util.getCsvSeparatedString(validFormats);
						//overDriveRecord.addFormats(validFormats);
						if (rawMetadataDecoded != null) {
							loadOverDriveIdentifiers(groupedWork, rawMetadataDecoded, primaryFormat);
						}

						long maxFormatBoost = 1;
						for (String curFormat : validFormats) {
							long formatBoost = 1;
							try {
								formatBoost = Long.parseLong(indexer.translateSystemValue("format_boost_overdrive", curFormat.replace(' ', '_'), identifier));
							} catch (Exception e) {
								logger.warn("Could not translate format boost for " + primaryFormat);
							}
							if (formatBoost > maxFormatBoost) {
								maxFormatBoost = formatBoost;
							}
						}
						overDriveRecord.setFormatBoost(maxFormatBoost);

						overDriveRecord.setEdition("");
						overDriveRecord.setPrimaryLanguage(primaryLanguage);
						overDriveRecord.setPublisher(metadata.get("publisher"));
						overDriveRecord.setPublicationDate(metadata.get("publicationDate"));
						overDriveRecord.setPhysicalDescription("");

						//Load availability & determine which scopes are valid for the record
						getProductAvailabilityStmt.setLong(1, productId);
						ResultSet availabilityRS = getProductAvailabilityStmt.executeQuery();

						int totalCopiesOwned = 0;
						while (availabilityRS.next()) {
							//Just create one item for each with a list of sub formats.
							ItemInfo itemInfo = new ItemInfo();
							itemInfo.seteContentSource("OverDrive");
							itemInfo.setIsEContent(true);
							itemInfo.setShelfLocation("Online OverDrive Collection");
							itemInfo.setCallNumber("Online OverDrive");
							itemInfo.setSortableCallNumber("Online OverDrive");
							itemInfo.setDateAdded(dateAdded);

							overDriveRecord.addItem(itemInfo);

							long libraryId = availabilityRS.getLong("libraryId");
							boolean available = availabilityRS.getBoolean("available");

							itemInfo.setFormat(primaryFormat);
							itemInfo.setSubFormats(detailedFormats);
							itemInfo.setFormatCategory(formatCategory);

							//Need to set an identifier based on the scope so we can filter later.
							itemInfo.setItemIdentifier(Long.toString(libraryId));

							//TODO: Check to see if this is a pre-release title.  If not, suppress if the record has 0 copies owned
							int copiesOwned = availabilityRS.getInt("copiesOwned");
							itemInfo.setNumCopies(copiesOwned);
							totalCopiesOwned = Math.max(copiesOwned, totalCopiesOwned);

							if (available) {
								itemInfo.setDetailedStatus("Available Online");
							} else {
								itemInfo.setDetailedStatus("Checked Out");
							}

							boolean isAdult = targetAudience.equals("Adult");
							boolean isTeen = targetAudience.equals("Young Adult");
							boolean isKids = targetAudience.equals("Juvenile");
							if (libraryId == -1) {
								for (Scope scope : indexer.getScopes()) {
									if (scope.isIncludeOverDriveCollection()) {
										//Check based on the audience as well
										boolean okToInclude = false;
										if (isAdult && scope.isIncludeOverDriveAdultCollection()) {
											okToInclude = true;
										}
										if (isTeen && scope.isIncludeOverDriveTeenCollection()) {
											okToInclude = true;
										}
										if (isKids && scope.isIncludeOverDriveKidsCollection()) {
											okToInclude = true;
										}
										if (okToInclude) {
											ScopingInfo scopingInfo = itemInfo.addScope(scope);
											scopingInfo.setAvailable(available);
											scopingInfo.setHoldable(true);

											if (available) {
												scopingInfo.setStatus("Available Online");
												scopingInfo.setGroupedStatus("Available Online");
											} else {
												scopingInfo.setStatus("Checked Out");
												scopingInfo.setGroupedStatus("Checked Out");
											}
										}
									}
								}
							} else {
								for (Scope curScope : indexer.getScopes()) {
									if (curScope.isIncludeOverDriveCollection() && curScope.getLibraryId().equals(libraryId)) {
										boolean okToInclude = false;
										if (isAdult && curScope.isIncludeOverDriveAdultCollection()) {
											okToInclude = true;
										}
										if (isTeen && curScope.isIncludeOverDriveTeenCollection()) {
											okToInclude = true;
										}
										if (isKids && curScope.isIncludeOverDriveKidsCollection()) {
											okToInclude = true;
										}
										if (okToInclude) {
											ScopingInfo scopingInfo = itemInfo.addScope(curScope);
											scopingInfo.setAvailable(available);
											scopingInfo.setHoldable(true);
											if (curScope.isLocationScope()) {
												scopingInfo.setLocallyOwned(true);
												scopingInfo.setLibraryOwned(true);
											}
											if (curScope.isLibraryScope()) {
												scopingInfo.setLibraryOwned(true);
											}
											if (available) {
												scopingInfo.setStatus("Available Online");
												scopingInfo.setGroupedStatus("Available Online");
											} else {
												scopingInfo.setStatus("Checked Out");
												scopingInfo.setGroupedStatus("Checked Out");
											}
										}
									}
								}

							}//End processing availability
						}
						groupedWork.addHoldings(totalCopiesOwned);
					}
					numCopiesRS.close();
				}
			}
			productRS.close();
		} catch (JSONException e) {
			logger.error("Error loading information from JSON for overdrive title", e);
		} catch (SQLException e) {
			logger.error("Error loading information from Database for overdrive title", e);
		}

	}

	private void loadOverDriveIdentifiers(GroupedWorkSolr groupedWork, JSONObject productMetadata, String primaryFormat) throws JSONException {
		JSONArray formats = productMetadata.getJSONArray("formats");
		for (int i = 0; i < formats.length(); i++){
			JSONObject curFormat = formats.getJSONObject(i);
			//Things like videos do not have identifiers so we need to check for the lack here
			if (curFormat.has("identifiers")){
				JSONArray identifiers = curFormat.getJSONArray("identifiers");
				for (int j = 0; j < identifiers.length(); j ++){
					JSONObject curIdentifier = identifiers.getJSONObject(j);
					String type = curIdentifier.getString("type");
					String value = curIdentifier.getString("value");
					//For now, ignore anything that isn't an ISBN
					if (type.equals("ISBN")){
						groupedWork.addIsbn(value, primaryFormat);
					}else if (type.equals("UPC")){
						groupedWork.addUpc(value);
					}
				}
			}
		}
	}

	/**
	 * Load information based on subjects for the record
	 *
	 * @param groupedWork    The Grouped Work being updated
	 * @param productMetadata   JSON representing the raw data metadata from OverDrive
	 * @return The target audience for use later in scoping
	 * @throws JSONException	Exception if something goes horribly wrong
	 */
	private String loadOverDriveSubjects(GroupedWorkSolr groupedWork, JSONObject productMetadata) throws JSONException {
		//Load subject data

		HashSet<String> topics = new HashSet<>();
		HashSet<String> genres = new HashSet<>();
		HashMap<String, Integer> literaryForm = new HashMap<>();
		HashMap<String, Integer> literaryFormFull = new HashMap<>();
		String targetAudience = "Adult";
		String targetAudienceFull = "Adult";
		if (productMetadata.has("subjects")){
			JSONArray subjects = productMetadata.getJSONArray("subjects");
			for (int i = 0; i < subjects.length(); i++){
				String curSubject = subjects.getJSONObject(i).getString("value");
				if (curSubject.contains("Nonfiction")){
					Util.addToMapWithCount(literaryForm, "Non Fiction");
					Util.addToMapWithCount(literaryFormFull, "Non Fiction");
					genres.add("Non Fiction");
				}else	if (curSubject.contains("Fiction")){
					Util.addToMapWithCount(literaryForm, "Fiction");
					Util.addToMapWithCount(literaryFormFull, "Fiction");
					genres.add("Fiction");
				}

				if (curSubject.contains("Poetry")){
					Util.addToMapWithCount(literaryForm, "Fiction");
					Util.addToMapWithCount(literaryFormFull, "Poetry");
				}else if (curSubject.contains("Essays")){
					Util.addToMapWithCount(literaryForm, "Non Fiction");
					Util.addToMapWithCount(literaryFormFull, curSubject);
				}else if (curSubject.contains("Short Stories") || curSubject.contains("Drama")){
					Util.addToMapWithCount(literaryForm, "Fiction");
					Util.addToMapWithCount(literaryFormFull, curSubject);
				}

				if (curSubject.contains("Juvenile")){
					targetAudience = "Juvenile";
					targetAudienceFull = "Juvenile";
				}else if (curSubject.contains("Young Adult")){
					targetAudience = "Young Adult";
					targetAudienceFull = "Adolescent (14-17)";
				}else if (curSubject.contains("Picture Book")){
					targetAudience = "Juvenile";
					targetAudienceFull = "Preschool (0-5)";
				}else if (curSubject.contains("Beginning Reader")){
					targetAudience = "Juvenile";
					targetAudienceFull = "Primary (6-8)";
				}

				topics.add(curSubject);
			}
			groupedWork.addTopic(topics);
			groupedWork.addTopicFacet(topics);
			groupedWork.addGenre(genres);
			groupedWork.addGenreFacet(genres);
			if (literaryForm.size() > 0){
				groupedWork.addLiteraryForms(literaryForm);
			}
			if (literaryFormFull.size() > 0){
				groupedWork.addLiteraryFormsFull(literaryFormFull);
			}
		}

		groupedWork.addTargetAudience(targetAudience);
		groupedWork.addTargetAudienceFull(targetAudienceFull);

		return targetAudience;
	}

	private String loadOverDriveLanguages(GroupedWorkSolr groupedWork, JSONObject productMetadata, String identifier) throws JSONException {
		String primaryLanguage = null;
		if (productMetadata.has("languages")) {
            JSONArray languagesFromMetadata = productMetadata.getJSONArray("languages");

            //Load languages
            HashSet<String> languages = new HashSet<>();
            for (int i = 0; i < languagesFromMetadata.length(); i++) {
                JSONObject curLanguageObj = languagesFromMetadata.getJSONObject(i);
                String language = curLanguageObj.getString("name");
                languages.add(language);
                if (primaryLanguage == null) {
                    primaryLanguage = language;
                }
                String languageCode = curLanguageObj.getString("code");
                String languageBoost = indexer.translateSystemValue("language_boost", languageCode, identifier);
                if (languageBoost != null) {
                    Long languageBoostVal = Long.parseLong(languageBoost);
                    groupedWork.setLanguageBoost(languageBoostVal);
                }
                String languageBoostEs = indexer.translateSystemValue("language_boost_es", languageCode, identifier);
                if (languageBoostEs != null) {
                    Long languageBoostVal = Long.parseLong(languageBoostEs);
                    groupedWork.setLanguageBoostSpanish(languageBoostVal);
                }
            }
            groupedWork.setLanguages(languages);
        }else {
		    groupedWork.addLanguage("English");
        }

		if (primaryLanguage == null){
			primaryLanguage = "English";
		}
		return primaryLanguage;
	}

	private HashSet<String> loadOverDriveFormats(Long productId, String identifier) throws SQLException {
		//Load formats
		getProductFormatsStmt.setLong(1, productId);
		ResultSet formatsRS = getProductFormatsStmt.executeQuery();
		HashSet<String> formats = new HashSet<>();
		Long formatBoost = 1L;
		while (formatsRS.next()){
			String format = formatsRS.getString("name");
			formats.add(format);
			String formatBoostStr = indexer.translateSystemValue("format_boost_overdrive", format.replace(' ', '_'), identifier);
			try{
				Long curFormatBoost = Long.parseLong(formatBoostStr);
				if (curFormatBoost > formatBoost){
					formatBoost = curFormatBoost;
				}
			}catch (NumberFormatException e){
				logger.warn("Could not parse format_boost " + formatBoostStr);
			}
		}
		formatsRS.close();

		return formats;
	}

	private HashMap<String, String> loadOverDriveMetadata(GroupedWorkSolr groupedWork, long productId, String format) throws SQLException {
		HashMap<String, String> returnMetadata = new HashMap<>();
		//Load metadata
		getProductMetadataStmt.setLong(1, productId);
		ResultSet metadataRS = getProductMetadataStmt.executeQuery();
		if (metadataRS.next()){
			returnMetadata.put("sortTitle", metadataRS.getString("sortTitle"));
			String publisher = metadataRS.getString("publisher");
			groupedWork.addPublisher(publisher);
			returnMetadata.put("publisher", publisher);
			String publicationDate = metadataRS.getString("publishDate");
			groupedWork.addPublicationDate(publicationDate);
			returnMetadata.put("publicationDate", publicationDate);
			//Need to divide this because it seems to be all time checkouts for all libraries, not just our libraries
			//Hopefully OverDrive will give us better stats in the near future that we can use.
			groupedWork.addPopularity(metadataRS.getFloat("popularity") / 500f);
			String shortDescription = metadataRS.getString("shortDescription");
			groupedWork.addDescription(shortDescription, format);
			String fullDescription = metadataRS.getString("fullDescription");
			groupedWork.addDescription(fullDescription, format);

			returnMetadata.put("rawMetadata", metadataRS.getString("rawData"));
		}
		metadataRS.close();
		return returnMetadata;
	}
}
