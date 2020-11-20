#!/bin/sh

if [ -z "$1" ]
  then
    echo "To use, run with start, stop or restart for the first parameter."
fi

if [[ ( "$1" == "stop" ) || ( "$1" == "restart") ]]
	then
    	/usr/local/aspen-discovery/sites/default/solr-7.6.0/bin/solr stop -p 8080 -s "/data/aspen-discovery/nashville.production/solr7" -d "/usr/local/aspen-discovery/sites/default/solr-7.6.0/server"
fi

if [[ ( "$1" == "start" ) || ( "$1" == "restart") ]]
	then
    	/usr/local/aspen-discovery/sites/default/solr-7.6.0/bin/solr start -m 30g -p 8080 -s "/data/aspen-discovery/nashville.production/solr7" -d "/usr/local/aspen-discovery/sites/default/solr-7.6.0/server"
fi
