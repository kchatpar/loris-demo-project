<?php
/**
 * Media uploader.
 *
 * Handles media upload and update actions received from a front-end ajax call
 *
 * PHP Version 5
 *
 * @category Loris
 * @package  Media
 * @author   Alex I. <ailea.mcin@gmail.com>
 * @license  Loris license
 * @link     https://github.com/aces/Loris-Trunk
 */

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == "getData") {
        viewData();
    } else if ($action == "upload") {
        uploadFile();
    } else if ($action == "edit") {
        editFile();
    }
}

/**
 * Handles the media update/edit process
 *
 * @throws DatabaseException
 *
 * @return void
 */
function editFile()
{
    $db   =& Database::singleton();
    $user =& User::singleton();
    if (!$user->hasPermission('media_write')) {
        header("HTTP/1.1 403 Forbidden");
        exit;
    }

    // Read JSON from STDIN
    $stdin       = file_get_contents('php://input');
    $req         = json_decode($stdin, true);
    $idMediaFile = $req['idMediaFile'];

    if (!$idMediaFile) {
        showError("Error! Invalid media file ID!");
    }

    $updateValues = [
                     'date_taken' => $req['dateTaken'],
                     'comments'   => $req['comments'],
                     'hide_file'  => $req['hideFile'] ? $req['hideFile'] : 0,
                    ];

    try {
        $db->update('media', $updateValues, ['id' => $idMediaFile]);
    } catch (DatabaseException $e) {
        showError("Could not update the file. Please try again!");
    }

}


/**
 * Handles the media upload process
 *
 * @throws DatabaseException
 *
 * @return void
 */
function uploadFile()
{
    $uploadNotifier = new NDB_Notifier(
        "media",
        "upload"
    );

    $db     =& Database::singleton();
    $config = NDB_Config::singleton();
    $user   =& User::singleton();
    if (!$user->hasPermission('media_write')) {
        header("HTTP/1.1 403 Forbidden");
        exit;
    }

    // Validate media path and destination folder
    $mediaPath = $config->getSetting('mediaPath');

    if (!isset($mediaPath)) {
        showError("Error! Media path is not set in Loris Settings!");
        exit;
    }

    if (!file_exists($mediaPath)) {
        showError("Error! The upload folder '$mediaPath' does not exist!");
        exit;
    }

    // Process posted data
    $pscid      = isset($_POST['pscid']) ? $_POST['pscid'] : null;
    $visit      = isset($_POST['visitLabel']) ? $_POST['visitLabel'] : null;
    $instrument = isset($_POST['instrument']) ? $_POST['instrument'] : null;
    $site       = isset($_POST['forSite']) ? $_POST['forSite'] : null;
    $dateTaken  = isset($_POST['dateTaken']) ? $_POST['dateTaken'] : null;
    $comments   = isset($_POST['comments']) ? $_POST['comments'] : null;
    $language   = isset($_POST['language']) ? $_POST['language'] : null;

    // If required fields are not set, show an error
    if (!isset($_FILES) || !isset($pscid) || !isset($visit) || !isset($site)) {
        showError("Please fill in all required fields!");
        return;
    }
    $fileName  = preg_replace('/\s/', '_', $_FILES["file"]["name"]);
    $fileType  = $_FILES["file"]["type"];
    $extension = pathinfo($fileName)['extension'];

    if (!isset($extension)) {
        showError("Please make sure your file has a valid extension!");
        return;
    }

    $userID = $user->getData('UserID');

    $sessionID = $db->pselectOne(
        "SELECT s.ID as session_id FROM candidate c " .
        "LEFT JOIN session s USING(CandID) WHERE c.PSCID = :v_pscid AND " .
        "s.Visit_label = :v_visit_label AND s.CenterID = :v_center_id",
        [
         'v_pscid'       => $pscid,
         'v_visit_label' => $visit,
         'v_center_id'   => $site,
        ]
    );

    if (!isset($sessionID) || count($sessionID) < 1) {
        showError(
            "Error! A session does not exist for candidate '$pscid'' " .
            "and visit label '$visit'."
        );

        return;
    }

    // Build insert query
    $query = [
              'session_id'    => $sessionID,
              'instrument'    => $instrument,
              'date_taken'    => $dateTaken,
              'comments'      => $comments,
              'file_name'     => $fileName,
              'file_type'     => $fileType,
              'data_dir'      => $mediaPath,
              'uploaded_by'   => $userID,
              'hide_file'     => 0,
              'date_uploaded' => date("Y-m-d H:i:s"),
              'language_id'   => $language,
             ];
    //############################ DEMO ############################
    if (unlink($_FILES["file"]["tmp_name"])) {
    //############################ DEMO ############################
        $existingFiles = getFilesList();
        $idMediaFile   = array_search($fileName, $existingFiles);
        try {
            // Override db record if file_name already exists
            if ($idMediaFile) {
                $db->update('media', $query, ['id' => $idMediaFile]);
            } else {
                $db->insert('media', $query);
            }
            //############################ DEMO ############################\
            showError(
                "The Demo server does not accept file uploads. 
                The database has been updated however to maintain the illusion."
            );
            //############################ DEMO ############################

        } catch (DatabaseException $e) {
            showError("Could not upload the file. Please try again!");
        }
    } else {
        showError("Could not upload the file. Please try again!");
    }
}

/**
 * Handles the media view data process
 *
 * @return void
 */
function viewData()
{
    $user =& User::singleton();
    if (!$user->hasPermission('media_read')) {
        header("HTTP/1.1 403 Forbidden");
        exit;
    }
    echo json_encode(getUploadFields());
}

/**
 * Returns a list of fields from database
 *
 * @return array
 * @throws DatabaseException
 */
function getUploadFields()
{

    $db =& Database::singleton();

    $instruments = $db->pselect(
        "SELECT Test_name FROM test_names ORDER BY Test_name",
        []
    );
    $candidates  = $db->pselect(
        "SELECT CandID, PSCID FROM candidate ORDER BY PSCID",
        []
    );

    $instrumentsList = toSelect($instruments, "Test_name", null);
    $candidatesList  = toSelect($candidates, "PSCID", null);
    $candIdList      = toSelect($candidates, "CandID", "PSCID");
    $visitList       = Utility::getVisitList();
    $siteList        = Utility::getSiteList(false);
    $languageList    = Utility::getLanguageList();

    // Build array of session data to be used in upload media dropdowns
    $sessionData    = [];
    $sessionRecords = $db->pselect(
        "SELECT c.PSCID, s.Visit_label, s.CenterID, f.Test_name " .
        "FROM candidate c ".
        "LEFT JOIN session s USING(CandID) ".
        "LEFT JOIN flag f ON (s.ID=f.SessionID) ".
        "ORDER BY c.PSCID ASC",
        []
    );

    foreach ($sessionRecords as $record) {

        // Populate sites
        if (!isset($sessionData[$record["PSCID"]]['sites'])) {
            $sessionData[$record["PSCID"]]['sites'] = [];
        }
        if ($record["CenterID"] !== null && !in_array(
            $record["CenterID"],
            $sessionData[$record["PSCID"]]['sites'],
            true
        )
        ) {
            $sessionData[$record["PSCID"]]['sites'][$record["CenterID"]]
                = $siteList[$record["CenterID"]];
        }

        // Populate visits
        if (!isset($sessionData[$record["PSCID"]]['visits'])) {
            $sessionData[$record["PSCID"]]['visits'] = [];
        }
        if ($record["Visit_label"] !== null && !in_array(
            $record["Visit_label"],
            $sessionData[$record["PSCID"]]['visits'],
            true
        )
        ) {
            $sessionData[$record["PSCID"]]['visits'][$record["Visit_label"]]
                = $record["Visit_label"];
        }

        // Populate instruments
        $visit = $record["Visit_label"];
        $pscid =$record["PSCID"];

        if (!isset($sessionData[$pscid]['instruments'][$visit])) {
            $sessionData[$pscid]['instruments'][$visit] = [];
        }
        if (!isset($sessionData[$pscid]['instruments']['all'])) {
            $sessionData[$pscid]['instruments']['all'] = [];
        }

        if ($record["Test_name"] !== null && !in_array(
            $record["Test_name"],
            $sessionData[$pscid]['instruments'][$visit],
            true
        )
        ) {
            $sessionData[$pscid]['instruments'][$visit][$record["Test_name"]]
                = $record["Test_name"];
            if (!in_array(
                $record["Test_name"],
                $sessionData[$pscid]['instruments']['all'],
                true
            )
            ) {
                $sessionData[$pscid]['instruments']['all'][$record["Test_name"]]
                    = $record["Test_name"];
            }

        }

    }

    // Build media data to be displayed when editing a media file
    $mediaData = null;
    if (isset($_GET['idMediaFile'])) {
        $idMediaFile = $_GET['idMediaFile'];
        $mediaData   = $db->pselectRow(
            "SELECT " .
            "m.session_id as sessionID, " .
            "(SELECT PSCID from candidate WHERE CandID=s.CandID) as pscid, " .
            "Visit_label as visitLabel, " .
            "instrument, " .
            "CenterID as forSite, " .
            "date_taken as dateTaken, " .
            "comments, " .
            "file_name as fileName, " .
            "hide_file as hideFile, " .
            "language_id as language," .
            "m.id FROM media m LEFT JOIN session s ON m.session_id = s.ID " .
            "WHERE m.id = $idMediaFile",
            []
        );
    }

    $result = [
               'candidates'  => $candidatesList,
               'candIDs'     => $candIdList,
               'visits'      => $visitList,
               'instruments' => $instrumentsList,
               'sites'       => $siteList,
               'mediaData'   => $mediaData,
               'mediaFiles'  => array_values(getFilesList()),
               'sessionData' => $sessionData,
               'language'    => $languageList,
              ];

    return $result;
}

/**
 * Utility function to return errors from the server
 *
 * @param string $message error message to display
 *
 * @return void
 */
function showError($message)
{
    if (!isset($message)) {
        $message = 'An unknown error occurred!';
    }
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json; charset=UTF-8');
    die(json_encode(['message' => $message]));
}

/**
 * Utility function to convert data from database to a
 * (select) dropdown friendly format
 *
 * @param array  $options array of options
 * @param string $item    key
 * @param string $item2   value
 *
 * @return array
 */
function toSelect($options, $item, $item2)
{
    $selectOptions = [];

    $optionsValue = $item;
    if (isset($item2)) {
        $optionsValue = $item2;
    }

    foreach ($options as $key => $value) {
        $selectOptions[$options[$key][$optionsValue]] = $options[$key][$item];
    }

    return $selectOptions;
}

/**
 * Returns an array of (id, file_name) pairs from media table
 *
 * @return array
 * @throws DatabaseException
 */
function getFilesList()
{
    $db       =& Database::singleton();
    $fileList = $db->pselect("SELECT id, file_name FROM media", []);

    $mediaFiles = [];
    foreach ($fileList as $row) {
        $mediaFiles[$row['id']] = $row['file_name'];
    }

    return $mediaFiles;
}
