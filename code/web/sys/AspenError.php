<?php


class AspenError extends DataObject
{
    static $errorCallback = null;

    public $__table = 'errors';
    public $id;
	public $module;
	public $action;
	public $url;
	public $message;
	public $backtrace;
	public $timestamp;

	/**
	 * Create a new Aspen Error.  For new Errors raised by the system, message should be filled out.
	 * For searching old errors, provide no parameters
	 *
	 * @param null $message
	 * @param null $backtrace
	 */
	public function __construct($message = null, $backtrace = null){
		if ($message != null){
			$this->url = $_SERVER['REQUEST_URI'];
			global $module;
			global $action;
			$this->module = $module;
			$this->action = $action;
			$this->timestamp = time();

			$this->message = $message;
			if ($backtrace == null){
				$this->backtrace = debug_backtrace();
			}else{
				$this->backtrace = $backtrace;
			}
		}
    }

	public static function getObjectStructure()
	{
		$structure = array(
			'id' => array('property'=>'id', 'type'=>'label', 'label'=>'Id', 'description'=>'The unique id'),
			'module' => array('property'=>'module', 'type'=>'label', 'label'=>'Module', 'description'=>'The Module that caused the error'),
			'action' => array('property'=>'action', 'type'=>'label', 'label'=>'Action', 'description'=>'The Action that caused the error'),
			'url' => array('property'=>'url', 'type'=>'label', 'label'=>'Url', 'description'=>'The URL that caused the error'),
			'message' => array('property'=>'message', 'type'=>'label', 'label'=>'Message', 'description'=>'A description of the error'),
			'backtrace' => array('property'=>'backtrace', 'type'=>'label', 'label'=>'Backtrace', 'description'=>'The trace that led to the error'),
			'timestamp' => array('property'=>'timestamp', 'type'=>'timestamp', 'label'=>'Timestamp', 'description'=>'When the error occurred'),
		);
		return $structure;
	}

	public function getMessage(){
        return $this->message;
    }

    public function toString()
    {
        return $this->message;
    }

    /**
     * Run the Error handler
     *
     * @param string|AspenError $error
     */
    static function raiseError($error) {
        if (is_string($error)){
            $error = new AspenError($error);
        }
        $error->handleAspenError();
    }
    /**
     * Handle an error raised by aspen
     *
     * TODO: When we are loading AJAX information, we should return a JSON formatted error rather than an HTML page
     *
     * @return null
     */
    function handleAspenError(){
        global $errorHandlingEnabled;
        if (isset($errorHandlingEnabled) && $errorHandlingEnabled == false){
            return;
        }
        global $configArray;

        // It would be really bad if an error got raised from within the error handler;
        // we would go into an infinite loop and run out of memory.  To avoid this,
        // we'll set a static value to indicate that we're inside the error handler.
        // If the error handler gets called again from within itself, it will just
        // return without doing anything to avoid problems.  We know that the top-level
        // call will terminate execution anyway.
        static $errorAlreadyOccurred = false;
        if ($errorAlreadyOccurred) {
            return;
        } else {
            $errorAlreadyOccurred = true;
        }

        global $aspenUsage;
        $aspenUsage->pagesWithErrors++;
	    $aspenUsage->update();

	    try{
		    $this->insert();
	    }catch(Exception $e){
	    	//Table has not been created yet
	    }


        //Clear any output that has been generated so far so the user just gets the error message.
        if (!$configArray['System']['debug']){
            @ob_clean();
            header("Content-Type: text/html");
        }

        // Display an error screen to the user:
        global $interface;
        if (!isset($interface) || $interface == false){
            $interface = new UInterface();
        }

        $interface->assign('error', $this);
        $interface->assign('debug', $configArray['System']['debug']);
        $interface->setTemplate('../error.tpl');
        $interface->display('layout.tpl');

        // Exceptions we don't want to log
        $doLog = true;
        // Microsoft Web Discussions Toolbar polls the server for these two files
        //    it's not script kiddie hacking, just annoying in logs, ignore them.
        if (strpos($_SERVER['REQUEST_URI'], "cltreq.asp") !== false) $doLog = false;
        if (strpos($_SERVER['REQUEST_URI'], "owssvr.dll") !== false) $doLog = false;
        // If we found any exceptions, finish here
        if (!$doLog) exit();

        // Log the error for administrative purposes -- we need to build a variety
        // of pieces so we can supply information at five different verbosity levels:
        $baseError = $this->toString();
        /*$basicServer = " (Server: IP = {$_SERVER['REMOTE_ADDR']}, " .
            "Referer = " . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '') . ", " .
            "User Agent = " . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') . ", " .
            "Request URI = {$_SERVER['REQUEST_URI']})";*/
        $detailedServer = "\nServer Context:\n" . print_r($_SERVER, true);
        $basicBacktrace = "\nBacktrace:\n";
        if (is_array($this->backtrace)) {
            foreach($this->backtrace as $line) {
                $basicBacktrace .= (isset($line['file']) ? $line['file'] : 'none') . "  line " . (isset($line['line']) ? $line['line'] : 'none') . " - " .
                    "class = " . (isset($line['class']) ? $line['class'] : 'none') . ", function = " . (isset($line['function']) ? $line['function'] : 'none') . "\n";
            }
        }
        //$detailedBacktrace = "\nBacktrace:\n" . print_r($error->backtrace, true);
        $errorDetails = $baseError . $detailedServer . $basicBacktrace;

        global $logger;
        $logger->log($errorDetails, Logger::LOG_ERROR);

        exit();
    }
}