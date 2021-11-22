<?php

namespace Antsstyle\NFTArtistBlocker\Core;

use Antsstyle\NFTArtistBlocker\Credentials\DB;

class CoreDB {

    const options = [
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ];

    public static $databaseConnection;

    public static function checkCentralisedBlockListForAllUsers() {
        $selectQuery = "SELECT * FROM users INNER JOIN usercentralisedblocklistrecords "
                . "ON users.twitterid=usercentralisedblocklistrecords.usertwitterid WHERE "
                . "highestblockedcentralid < (SELECT MAX(id) FROM centralisedblocklist) AND usertwitterid IN "
                . "(SELECT usertwitterid FROM userautomationsettings WHERE centraldatabaseoperation=? OR centraldatabaseoperation=?)";
        $selectStmt = CoreDB::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute(["Block", "Mute"]);
        if (!$success) {
            error_log("Could not get users to block centralised blocklist entries for, returning.");
            return;
        }
        while ($userRow = $selectStmt->fetch()) {
            $userTwitterID = $userRow['usertwitterid'];
            $operation = $userRow['centraldatabaseoperation'];
            $userHighestBlockedID = $userRow['highestblockedcentralid'];
            $insertQuery = "SET @usertwitterid = $userTwitterID; "
                    . "INSERT INTO entriestoprocess (subjectusertwitterid,objectusertwitterid,operation) "
                    . "SELECT @usertwitterid, blockableusertwitterid, '$operation' FROM centralisedblocklist WHERE "
                    . "centralisedblocklist.id > ?"
                    . " AND blockusertwitterid NOT IN (SELECT objectusertwitterid FROM "
                    . "userinitialblockrecords WHERE subjectusertwitterid=? AND operation='$operation') ON DUPLICATE KEY UPDATE operation='$operation'";
            $insertStmt = CoreDB::$databaseConnection->prepare($insertQuery);
            $insertStmt->execute([$userHighestBlockedID]);
        }
    }

    public static function getMaxCentralBlockListID() {
        $selectQuery = "SELECT MAX(id) FROM centralisedblocklist";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute();
        if (!$success) {
            error_log("Could not retrieve last blocklist update check date, returning.");
            return -1;
        }
        return $selectStmt->fetchColumn();
    }

    public static function checkBlockListUpdates() {
        $selectQuery = "SELECT value FROM centralconfiguration WHERE name=?";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute([Config::LAST_BLOCKLIST_UPDATE_CHECK]);
        if (!$success) {
            error_log("Could not retrieve last blocklist update check date, returning.");
            return;
        }
        $dateString = $selectStmt->fetchColumn();
        $selectQuery = "SELECT MAX(dateadded) FROM blocklistentries";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute();
        if (!$success) {
            error_log("Could not retrieve last blocklist update check date, returning.");
            return;
        }
        $maxDateAdded = $selectStmt->fetchColumn();
        if (!$maxDateAdded) {
            error_log("No blocklist entries exist, no need to check further - exiting update.");
            return;
        }
        if (!$dateString) {
            $updateQuery = "UPDATE centralconfiguration SET value=? WHERE name=?";
            $updateStmt = self::$databaseConnection->prepare($updateQuery);
            $success = $updateStmt->execute([$maxDateAdded, Config::LAST_BLOCKLIST_UPDATE_CHECK]);
            if (!$success) {
                error_log("Failed to update last blocklist update check config item!");
                return;
            }
            return;
        }
        $dateTime = strtotime($dateString);
        $maxDateTime = strtotime($maxDateAdded);
        if ($maxDateTime > $dateTime) {
            // New entries since last check date; update blocklists for users
            $selectQuery = "SELECT * FROM blocklistentries WHERE dateadded > ?";
            $selectStmt = self::$databaseConnection->prepare($selectQuery);
            $success = $selectStmt->execute([$dateString]);
            if (!$success) {
                error_log("Could not retrieve last blocklist update check date, returning.");
                return;
            }
            self::$databaseConnection->beginTransaction();
            while ($row = $selectStmt->fetch()) {
                $userSelectQuery = "SELECT twitterid FROM users WHERE twitterid IN (SELECT usertwitterid FROM userblocklistrecords "
                        . "WHERE blocklistid=? AND lastoperation=?)";
                $userBlockSelectStmt = self::$databaseConnection->prepare($userSelectQuery);
                $success = $userBlockSelectStmt->execute([$row['blocklistid'], "Block"]);
                if (!$success) {
                    error_log("Failed to get list of users to block new blocklist entries for, returning.");
                    return;
                }

                $insertBlockQuery = "INSERT IGNORE INTO entriestoprocess (blocklistid,subjectusertwitterid,objectusertwitterid,operation) "
                        . "VALUES (?,?,?,?)";
                while ($userBlockTwitterID = $userBlockSelectStmt->fetchColumn()) {
                    $insertBlockStmt = self::$databaseConnection->prepare($insertBlockQuery);
                    $insertBlockStmt->execute([$row['blocklistid'], $userBlockTwitterID, $row['blockusertwitterid'], "Block"]);
                }

                $userMuteSelectStmt = self::$databaseConnection->prepare($userSelectQuery);
                $success = $userMuteSelectStmt->execute([$row['blocklistid'], "Mute"]);
                if (!$success) {
                    error_log("Failed to get list of users to mute new blocklist entries for, returning.");
                    return;
                }

                while ($userMuteTwitterID = $userMuteSelectStmt->fetchColumn()) {
                    $insertMuteStmt = self::$databaseConnection->prepare($insertBlockQuery);
                    $insertMuteStmt->execute([$row['blocklistid'], $userBlockTwitterID, $row['blockusertwitterid'], "Mute"]);
                }
            }
            $updateQuery = "UPDATE centralconfiguration SET value=? WHERE name=?";
            $updateStmt = self::$databaseConnection->prepare($updateQuery);
            $updateStmt->execute([$maxDateAdded, Config::LAST_BLOCKLIST_UPDATE_CHECK]);
            self::$databaseConnection->commit();
        }
    }

    public static function updateUserAutomationSettings($userTwitterID, $phraseSettings, $urlSettings, $nftProfilePicturesSettings,
            $cryptoUserNamesSettings, $NFTFollowersSettings, $centralDatabaseSettings, $followerWhitelistSettings) {
        if ($phraseSettings !== "noaction_phrases" && $phraseSettings !== "block_phrases" && $phraseSettings !== "mute_phrases") {
            return "input error";
        }
        if ($urlSettings !== "noaction_urls" && $urlSettings !== "block_urls" && $urlSettings !== "mute_urls") {
            return "input error";
        }
        if ($nftProfilePicturesSettings !== "noaction_nftprofilepictures" && $nftProfilePicturesSettings !== "block_nftprofilepictures" &&
                $nftProfilePicturesSettings !== "mute_nftprofilepictures") {
            return "input error";
        }
        if ($cryptoUserNamesSettings !== "noaction_cryptousernames" && $cryptoUserNamesSettings !== "block_cryptousernames" &&
                $cryptoUserNamesSettings !== "mute_cryptousernames") {
            return "input error";
        }
        if ($centralDatabaseSettings !== "noaction_centraldatabase" && $centralDatabaseSettings !== "block_centraldatabase" &&
                $centralDatabaseSettings !== "mute_centraldatabase") {
            return "input error";
        }
        if ($NFTFollowersSettings !== "noaction_nftfollowers" && $NFTFollowersSettings !== "block_nftfollowers" &&
                $NFTFollowersSettings !== "mute_nftfollowers") {
            return "input error";
        }
        if ($followerWhitelistSettings !== "enable_followerwhitelist" && $followerWhitelistSettings !== "disable_followerwhitelist") {
            return "input error";
        }
        $phraseArray = explode("_", $phraseSettings);
        $phraseString = ucfirst($phraseArray[0]);
        $urlArray = explode("_", $urlSettings);
        $urlString = ucfirst($urlArray[0]);
        $nftProfilePicturesArray = explode("_", $nftProfilePicturesSettings);
        $nftProfilePicturesString = ucfirst($nftProfilePicturesArray[0]);
        $cryptoUserNamesArray = explode("_", $cryptoUserNamesSettings);
        $cryptoUserNamesString = ucfirst($cryptoUserNamesArray[0]);
        $NFTFollowersArray = explode("_", $NFTFollowersSettings);
        $NFTFollowersString = ucfirst($NFTFollowersArray[0]);
        $centralDatabaseArray = explode("_", $centralDatabaseSettings);
        $centralDatabaseString = ucfirst($centralDatabaseArray[0]);
        $followerWhitelistArray = explode("_", $followerWhitelistSettings);
        if ($followerWhitelistArray[0] == "disable") {
            $followerWhitelistString = "N";
        } else {
            $followerWhitelistString = "Y";
        }
        $insertQuery = "INSERT INTO userautomationsettings (usertwitterid,matchingphraseoperation,nftprofilepictureoperation,"
                . "urlsoperation,cryptousernamesoperation,nftfollowersoperation,centraldatabaseoperation,whitelistfollowings) VALUES (?,?,?,?,?,?,?,?) "
                . "ON DUPLICATE KEY UPDATE matchingphraseoperation=?, "
                . "nftprofilepictureoperation=?, urlsoperation=?, cryptousernamesoperation=?, "
                . "nftfollowersoperation=?, centraldatabaseoperation=?, whitelistfollowings=?";
        $insertStmt = self::$databaseConnection->prepare($insertQuery);
        $success = $insertStmt->execute([$userTwitterID, $phraseString, $nftProfilePicturesString, $urlString, $cryptoUserNamesString,
            $NFTFollowersString, $centralDatabaseString, $followerWhitelistString, $phraseString,
            $nftProfilePicturesString, $urlString, $cryptoUserNamesString, $NFTFollowersString, $centralDatabaseString, $followerWhitelistString]);
        if (!$success) {
            error_log("Could not update automation settings for user ID $userTwitterID!");
        }
        return $success;
    }

    public static function getUserInfo($userTwitterID) {
        $selectQuery = "SELECT * FROM users WHERE twitterid=?";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute([$userTwitterID]);
        if (!$success) {
            error_log("Could not retrieve user information, returning.");
            return;
        }
        $row = $selectStmt->fetch();
        return $row;
    }

    public static function getUserAutomationSettings($userTwitterID) {
        $selectQuery = "SELECT * FROM userautomationsettings WHERE usertwitterid=?";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute([$userTwitterID]);
        if (!$success) {
            error_log("Could not retrieve user automation settings, returning.");
            return;
        }
        $row = $selectStmt->fetch();
        return $row;
    }

    public static function getUserBlocklistAutomationSettings($userTwitterID) {
        $selectQuery = "SELECT *,(SELECT name FROM blocklists WHERE userblocklistrecords.blocklistid=blocklists.id) AS name FROM "
                . "userautomationsettings INNER JOIN userblocklistrecords ON "
                . "userautomationsettings.usertwitterid=userblocklistrecords.usertwitterid WHERE userautomationsettings.usertwitterid=?";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute([$userTwitterID]);
        if (!$success) {
            error_log("Could not retrieve user blocklist automation settings, returning.");
            return;
        }
        $rows = $selectStmt->fetchAll();
        return $rows;
    }

    public static function getBlockablePhrases() {
        $selectQuery = "SELECT * FROM blockablephrases";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute();
        if (!$success) {
            error_log("Could not retrieve list of blockable phrases, returning.");
            return;
        }
        $rows = $selectStmt->fetchAll();
        return $rows;
    }

    function getBlockableURLs() {
        $selectQuery = "SELECT * FROM blockableurls";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute();
        if (!$success) {
            error_log("Could not retrieve list of blockable URLs, returning.");
            return;
        }
        $rows = $selectStmt->fetchAll();
        return $rows;
    }

    public static function getBlockableUsernameRegexes() {
        $selectQuery = "SELECT * FROM blockableusernameregexes";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute();
        if (!$success) {
            error_log("Could not retrieve list of blockable username regexes, returning.");
            return;
        }
        $rows = $selectStmt->fetchAll();
        return $rows;
    }

    public static function updateTwitterEndpointLogs($endpoint, $callCount) {
        $date = strtotime("Y-m-d");
        $insertQuery = "INSERT INTO twitterendpointlogs (date,endpoint,callcount) VALUES (?,?,?) "
                . "ON DUPLICATE KEY UPDATE callcount=callcount+?";
        $insertStmt = self::$databaseConnection->prepare($insertQuery);
        $success = $insertStmt->execute([$date, $endpoint, $callCount, $callCount]);
        if (!$success) {
            error_log("Could not update endpoints logs. Parameters were: $endpoint , $callCount");
        }
    }

    public static function deleteUser($userTwitterID) {
        $deleteQuery = "DELETE FROM users WHERE twitterid=?";
        $deleteStmt = self::$databaseConnection->prepare($deleteQuery);
        $success = $deleteStmt->execute([$userTwitterID]);
        if (!$success) {
            error_log("Could not delete user with twitter ID $userTwitterID");
        }
        return $success;
    }

    public static function insertBannedUsers($users) {
        $selectQuery = "SELECT id FROM blocklists WHERE name=?";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute(["NFT Artists"]);
        if (!$success) {
            error_log("Could not get id of NFT Artists blocklist, returning.");
            return;
        }
        $blockListID = $selectStmt->fetchColumn();
        $screenNameKeys = array_keys($users);
        $totalCount = count($users);
        $i = 0;
        $parameterString = "";
        $connection = new TwitterOAuth(APIKeys::consumer_key, APIKeys::consumer_secret);
        $connection->setRetries(1, 1);
        foreach ($screenNameKeys as $screenName) {
            $parameterString .= $screenName .= ",";
            $i++;
            if ($i % 100 == 0) {
                $userLookupResponse = $connection->post("users/lookup", $parameterString);
                $parameterString = "";
            }
        }
    }

    public static function insertUserInformation($access_token) {
        $accessToken = $access_token['oauth_token'];
        $accessTokenSecret = $access_token['oauth_token_secret'];
        $userTwitterID = $access_token['user_id'];
        $insertQuery = "INSERT INTO users (twitterid,accesstoken,accesstokensecret) VALUES (?,?,?) ON DUPLICATE KEY UPDATE "
                . "accesstoken=?, accesstokensecret=? ";
        $success = self::$databaseConnection->prepare($insertQuery)
                ->execute([$userTwitterID, $accessToken, $accessTokenSecret, $accessToken, $accessTokenSecret]);
        return $success;
    }

    public static function checkUserOperation($userTwitterID, $blockListName, $operation) {
        $selectQuery = "SELECT * FROM userblocklistrecords WHERE usertwitterid=? AND blocklistid=(SELECT id FROM blocklists WHERE name=?) "
                . "AND lastoperation=?";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute([$userTwitterID, $blockListName, $operation]);
        if (!$success) {
            error_log("Could not get id of NFT Artists blocklist, returning.");
            return;
        }
        $row = $selectStmt->fetch();
        if ($row) {
            return true;
        }
        return false;
    }

    public static function markListForUser($userTwitterID, $blockListName, $operation) {
        $operation = strtolower(filter_var($operation, FILTER_SANITIZE_STRING));
        $userTwitterID = filter_var($userTwitterID, FILTER_SANITIZE_NUMBER_INT);
        $doneAlready = self::checkUserOperation($userTwitterID, $blockListName, $operation);
        if ($doneAlready) {
            return true;
        }
        $insertQuery = "set @usertwitterid = $userTwitterID; "
                . "INSERT INTO entriestoprocess (subjectusertwitterid,blocklistid,objectusertwitterid,operation) "
                . "SELECT @usertwitterid, blocklistid, blockusertwitterid, '$operation' FROM blocklistentries WHERE blocklistid="
                . "(SELECT id FROM blocklists WHERE name=?) AND blockusertwitterid NOT IN (SELECT objectusertwitterid FROM "
                . "userinitialblockrecords WHERE subjectusertwitterid=? AND operation='$operation') ON DUPLICATE KEY UPDATE operation='$operation'";
        $success1 = self::$databaseConnection->prepare($insertQuery)
                ->execute([$blockListName]);
        if (!$success1) {
            return false;
        }
        $insertQuery = "set @usertwitterid = $userTwitterID; "
                . "INSERT INTO userblocklistrecords (usertwitterid,blocklistid,lastoperation) "
                . "SELECT @usertwitterid, id, '$operation' FROM blocklists WHERE name=?"
                . " ON DUPLICATE KEY UPDATE lastoperation='$operation'";
        $success2 = self::$databaseConnection->prepare($insertQuery)
                ->execute([$blockListName]);
        return $success2;
    }

    public static function insertCentralBlockListEntries($centralBlockListParams) {
        if (count($centralBlockListParams) == 0) {
            return;
        }
        $insertQuery = "INSERT IGNORE INTO centralisedblocklist (blockableusertwitterid, matchedfiltertype, matchedfiltercontent) "
                . "VALUES (?,?,?)";
        self::$databaseConnection->beginTransaction();
        foreach ($centralBlockListParams as $singleUserParams) {
            $insertStmt = self::$databaseConnection->prepare($insertQuery);
            $insertStmt->execute($singleUserParams);
        }
        self::$databaseConnection->commit();
    }

    public static function deleteProcessedEntries($deleteParams) {
        if (count($deleteParams) == 0) {
            return;
        }
        $deleteQuery = "DELETE FROM entriestoprocess WHERE id IN (?";
        for ($i = 1; $i < count($deleteParams); $i++) {
            $deleteQuery .= ",?";
        }
        $deleteQuery .= ")";
        $success = self::$databaseConnection->prepare($deleteQuery)
                ->execute($deleteParams);
        return $success;
    }

    public static function getBlockListNames() {
        $selectQuery = "SELECT name FROM blocklists";
        $selectStmt = self::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute();
        if (!$success) {
            error_log("Could not get list of users to process entries for, terminating.");
            return;
        }
        while ($row = $selectStmt->fetch()) {
            $blockListNames[] = $row['name'];
        }
        return $blockListNames;
    }

    public static function initialiseConnection() {
        try {
            $params = "mysql:host=" . DB::server_name . ";dbname=" . DB::database . ";port=" . DB::port;
            CoreDB::$databaseConnection = new \PDO($params, DB::username, DB::password, self::options);
        } catch (Exception $e) {
            error_log("Failed to create database connection.");
            echo "Failed to create database connection.";
            exit();
        }

        CoreDB::$databaseConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

}

CoreDB::initialiseConnection();
