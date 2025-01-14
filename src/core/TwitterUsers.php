<?php

namespace Antsstyle\NFTCryptoBlocker\Core;

use Antsstyle\NFTCryptoBlocker\Core\Config;
use Antsstyle\NFTCryptoBlocker\Core\StatusCode;
use Antsstyle\NFTCryptoBlocker\Credentials\AdminUserAuth;
use Antsstyle\NFTCryptoBlocker\Credentials\APIKeys;
use Antsstyle\NFTCryptoBlocker\Core\CoreDB;
use Abraham\TwitterOAuth\TwitterOAuth;
use Antsstyle\NFTCryptoBlocker\Core\LogManager;

class TwitterUsers {
    
    public static $logger;

    public static function testUserSearch($query) {
        $endpoint = "users/search";
        $params['count'] = 20;
        $params['q'] = $query;
        TwitterUsers::$logger->info("Query parameter: $query");
        $connection = new TwitterOAuth(APIKeys::consumer_key, APIKeys::consumer_secret,
                AdminUserAuth::access_token, AdminUserAuth::access_token_secret);
        $connection->setRetries(1, 1);
        for ($i = 0; $i < 1; $i++) {
            $params['page'] = $i;
            $response = $connection->get($endpoint, $params);
            CoreDB::updateTwitterEndpointLogs($endpoint, 1);
            $statusCode = Core::checkResponseHeadersForErrors($connection);
            if ($statusCode->httpCode != StatusCode::HTTP_QUERY_OK || $statusCode->twitterCode != StatusCode::NFTCRYPTOBLOCKER_QUERY_OK) {
                break;
            }
        }
        return $response;
    }

    public static function userSearchAllBlockableEntries() {
        $phrases = CoreDB::getBlockablePhrases();
        $urls = CoreDB::getBlockableURLs();
        $regexes = CoreDB::getBlockableUsernameRegexes();
        if (!$phrases || !$urls || !$regexes) {
            TwitterUsers::$logger->critical("Could not retrieve filters for user mentions, returning.");
            return;
        }
        foreach ($phrases as $phrase) {
            self::userSearch($phrase, $phrases, $urls, $regexes);
        }
        foreach ($urls as $url) {
            self::userSearch("url:" . $url, $phrases, $urls, $regexes);
        }
    }

    public static function userSearch($query, $phrases, $urls, $regexes) {
        $endpoint = "users/search";
        $params['count'] = 20;
        $params['q'] = $query;
        $connection = new TwitterOAuth(APIKeys::consumer_key, APIKeys::consumer_secret,
                AdminUserAuth::access_token, AdminUserAuth::access_token_secret);
        $connection->setRetries(1, 1);
        $insertParams = [];
        for ($i = 0; $i < 50; $i++) {
            $params['page'] = $i;
            $response = $connection->get($endpoint, $params);
            CoreDB::updateTwitterEndpointLogs($endpoint, 1);
            $statusCode = Core::checkResponseHeadersForErrors($connection);
            if ($statusCode->httpCode != StatusCode::HTTP_QUERY_OK || $statusCode->twitterCode != StatusCode::NFTCRYPTOBLOCKER_QUERY_OK) {
                break;
            }
            $subjectUserInfo['matchingphraseoperation'] = "Block";
            foreach ($response as $userObject) {
                $filtersMatched = self::checkNFTFilters($subjectUserInfo, $userObject, $phrases, $urls, $regexes);
                if ($filtersMatched) {
                    TwitterUsers::$logger->info("Filters matched for users/search! Object user ID: $userObject->id. Filter was:");
                    TwitterUsers::$logger->info(print_r($filtersMatched, true));

                    $insertParams[] = [$userObject->id, $filtersMatched['filtertype'],
                        $filtersMatched['filtercontent'], "users/search"];
                    // Add to entries to process along with reason information
                }
            }
        }
        return $response;
    }

    // Gets a user object, given an access token.
    public static function getUserObject($userAuth, $userTwitterID) {
        $params['user.fields'] = "entities,description,name,profile_image_url,url,username,id";
        $connection = new TwitterOAuth(APIKeys::consumer_key, APIKeys::consumer_secret,
                $userAuth['accesstoken'], $userAuth['accesstokensecret']);
        $connection->setApiVersion('2');
        $connection->setRetries(1, 1);
        $query = "users/" . $userTwitterID;
        $response = $connection->get($query, $params);
        CoreDB::updateTwitterEndpointLogs("users/:id", 1);
        $statusCode = Core::checkResponseHeadersForErrors($connection);
        if ($statusCode->httpCode != StatusCode::HTTP_QUERY_OK || $statusCode->twitterCode != StatusCode::NFTCRYPTOBLOCKER_QUERY_OK) {
            return null;
        }
        return $response;
    }

    public static function checkNFTFollowersForAllUsers() {
        $selectQuery = "SELECT value FROM centralconfiguration WHERE name=?";
        $selectStmt = CoreDB::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute([Config::FOLLOWER_CHECK_TIME_INTERVAL_SECONDS]);
        if (!$success) {
            TwitterUsers::$logger->critical("Could not get users to check NFT followers for, returning.");
            return;
        }
        $timeIntervalSeconds = $selectStmt->fetchColumn();
        if (!$timeIntervalSeconds) {
            // Default to 3600 seconds, i.e. 1 hour
            $timeIntervalSeconds = 3600;
        }
        $timeIntervalSecondsString = "-" . $timeIntervalSeconds . " seconds";
        $selectQuery = "SELECT * FROM users INNER JOIN userautomationsettings ON users.twitterid=userautomationsettings.usertwitterid"
                . " WHERE (nftfollowersoperation=? OR nftfollowersoperation=?) "
                . "AND (followersendreached=? OR followerslastcheckedtime <= ?) AND locked=?";
        $dateThreshold = date("Y-m-d H:i:s", strtotime($timeIntervalSecondsString));
        $selectStmt = CoreDB::$databaseConnection->prepare($selectQuery);
        $success = $selectStmt->execute(["Block", "Mute", "N", $dateThreshold, "N"]);
        if (!$success) {
            TwitterUsers::$logger->critical("Could not get users to check NFT followers for, returning.");
            return;
        }
        $phrases = CoreDB::getBlockablePhrases();
        $urls = CoreDB::getBlockableURLs();
        $regexes = CoreDB::getBlockableUsernameRegexes();
        if (!$phrases || !$urls || !$regexes) {
            TwitterUsers::$logger->critical("Could not retrieve filters for user mentions, returning.");
            return;
        }
        while ($userRow = $selectStmt->fetch()) {
            self::checkNFTFollowersForUser($userRow, $phrases, $urls, $regexes);
        }
    }

    public static function checkNFTFollowersForUser($userRow, $phrases, $urls, $regexes) {
        $params['max_results'] = 1000;
        $params['user.fields'] = "entities,description,name,profile_image_url,url,username,id";
        $accessToken = $userRow['accesstoken'];
        $accessTokenSecret = $userRow['accesstokensecret'];
        $followersPaginationToken = $userRow['followerspaginationtoken'];
        if ($followersPaginationToken != null && $userRow['followersendreached'] == "N") {
            $params['pagination_token'] = $followersPaginationToken;
        }
        $insertParams = [];
        $connection = new TwitterOAuth(APIKeys::consumer_key, APIKeys::consumer_secret,
                $accessToken, $accessTokenSecret);
        $connection->setApiVersion('2');
        $connection->setRetries(1, 1);
        $returnedPages = 0;
        $noMorePages = false;
        if ($userRow['followersendreached'] == "Y") {
            $followerCache = CoreDB::getFollowerCacheForUser($userRow['usertwitterid']);
        }
        // Max of 15 pages due to both rate limits, and to avoid excessively long running times for cronjobs
        $returnedFollowerIDs = [];
        while ($returnedPages < 15) {
            $query = "users/" . $userRow['usertwitterid'] . "/followers";
            $response = $connection->get($query, $params);
            CoreDB::updateTwitterEndpointLogs("users/:id/followers", 1);
            $statusCode = Core::checkResponseHeadersForErrors($connection, $userRow['twitterid']);
            if ($statusCode->httpCode != StatusCode::HTTP_QUERY_OK || $statusCode->twitterCode != StatusCode::NFTCRYPTOBLOCKER_QUERY_OK) {
                break;
            }
            $returnedPages++;
            $users = $response->data;
            if (!isset($users) || count($users) == 0) {
                $noMorePages = true;
                break;
            }

            foreach ($users as $objectUser) {
                if (count($returnedFollowerIDs) < 25) {
                    $returnedFollowerIDs[] = $objectUser->id;
                }

                // check if the tweet is one we want to examine
                // check description, profile picture: add block to entries to process if match found
                $filtersMatched = self::checkNFTFilters($userRow, $objectUser, $phrases, $urls, $regexes);
                if ($filtersMatched) {
                    TwitterUsers::$logger->info("Filters matched for user follower! Object user ID: $objectUser->id. Filter was:");
                    TwitterUsers::$logger->info(print_r($filtersMatched, true));
                    if ($filtersMatched['operation'] == "Block") {
                        $insertParams[] = [$userRow['usertwitterid'], $objectUser->id, "Block", $filtersMatched['filtertype'],
                            $filtersMatched['filtercontent'], "Y", "users/:id/followers"];
                        // Add to entries to process along with reason information
                    } else if ($filtersMatched['operation'] == "Mute") {
                        $insertParams[] = [$userRow['usertwitterid'], $objectUser->id, "Mute", $filtersMatched['filtertype'],
                            $filtersMatched['filtercontent'], "Y", "users/:id/followers"];
                        // Add to entries to process along with reason information
                    } else {
                        $userOp = $filtersMatched['operation'];
                        TwitterUsers::$logger->error("Unrecognised user automation operation, text was: $userOp");
                    }
                }
            }
            $meta = $response->meta;
            if (!isset($meta->next_token) && $userRow['followersendreached'] == "N") {
                $noMorePages = true;
                break;
            } else if (!isset($meta->next_token)) {
                break;
            }

            $params['pagination_token'] = $meta->next_token;

            if ($userRow['followersendreached'] == "Y") {
                if ($followerCache === false) {
                    $userTwitterID = $userRow['usertwitterid'];
                    TwitterUsers::$logger->error("Follower cache for user ID $userTwitterID could not be retrieved!");
                    break;
                }
                foreach ($returnedFollowerIDs as $returnedFollowerID) {
                    if (in_array($returnedFollowerID, $followerCache)) {
                        $encounteredCache = true;
                        break;
                    }
                }
                if ($encounteredCache) {
                    break;
                }
            }
        }

        $currentTimeString = date("Y-m-d H:i:s");

        if ($noMorePages) {
            if (!isset($params['pagination_token'])) {
                $params['pagination_token'] = null;
            }
            $updateQuery = "UPDATE users SET followerspaginationtoken=?, followersendreached=?, "
                    . "followerslastcheckedtime=? WHERE twitterid=?";
            $updateStmt = CoreDB::$databaseConnection->prepare($updateQuery);
            $updateStmt->execute([$params['pagination_token'], "Y", $currentTimeString, $userRow['usertwitterid']]);
        } else {
            $updateQuery = "UPDATE users SET followerspaginationtoken=?, "
                    . "followerslastcheckedtime=? WHERE twitterid=?";
            $updateStmt = CoreDB::$databaseConnection->prepare($updateQuery);
            $updateStmt->execute([$params['pagination_token'], $currentTimeString, $userRow['usertwitterid']]);
        }

        if (count($returnedFollowerIDs) == 25) {
            CoreDB::updateFollowerCacheForUser($userRow['usertwitterid'], $returnedFollowerIDs);
        }


        $insertQuery = "INSERT IGNORE INTO entriestoprocess (subjectusertwitterid,objectusertwitterid,operation,"
                . "matchedfiltertype,matchedfiltercontent,addtocentraldb,addedfrom) VALUES (?,?,?,?,?,?,?)";
        CoreDB::$databaseConnection->beginTransaction();
        foreach ($insertParams as $insertParamsForUser) {
            $insertStmt = CoreDB::$databaseConnection->prepare($insertQuery);
            $insertStmt->execute($insertParamsForUser);
        }
        CoreDB::$databaseConnection->commit();
    }

    public static function checkNFTFilters($subjectUserInfo, $objectUser, $phrases, $urls, $regexes) {
        $userURLHosts = [];
        $userURLs = $objectUser->entities->url;
        if (isset($userURLs)) {
            $userURLs = $objectUser->entities->url;
            if (is_array($userURLs) && count($userURLs) > 0) {
                $userURL = $userURLs[0]->expanded_url;
                $userURL = filter_var($userURL, FILTER_VALIDATE_URL);
                if ($userURL) {
                    $userURLHosts[] = strtolower(parse_url($userURL, PHP_URL_HOST));
                }
            }
        }
        $descriptionURLs = $objectUser->entities->description->urls;
        if (isset($descriptionURLs) && is_array($descriptionURLs)) {
            foreach ($descriptionURLs as $descriptionURL) {
                $expandedURL = $descriptionURL->expanded_url;
                if (isset($expandedURL)) {
                    $expandedURL = filter_var($expandedURL, FILTER_VALIDATE_URL);
                    if ($expandedURL) {
                        $userURLHosts[] = strtolower(parse_url($expandedURL, PHP_URL_HOST));
                    }
                }
            }
        }
        $userDescription = $objectUser->description;
        if ($subjectUserInfo['matchingphraseoperation'] == "Block" || $subjectUserInfo['matchingphraseoperation'] == "Mute") {
            foreach ($phrases as $phrase) {
                $lowerCasePhrase = strtolower($phrase['phrase']);
                // Check entities instead of text for hashtags and cashtags
                if (strpos($lowerCasePhrase, "#") === 0) {
                    if (isset($objectUser->entities->description->hashtags)) {
                        $hashtagObjects = $objectUser->entities->description->hashtags;
                        $phraseWithoutHash = substr($lowerCasePhrase, 1);
                        foreach ($hashtagObjects as $hashtagObject) {
                            $hashtag = $hashtagObject->tag;
                            $lowerCaseHashtag = strtolower($hashtag);
                            if ($lowerCaseHashtag === $phraseWithoutHash) {
                                return array("operation" => $subjectUserInfo['matchingphraseoperation'], "filtertype" => "matchingphrase",
                                    "filtercontent" => $phrase['phrase']);
                            }
                        }
                    }
                } else if (strpos($lowerCasePhrase, "$") === 0) {
                    if (isset($objectUser->entities->description->cashtags)) {
                        $cashtagObjects = $objectUser->entities->description->cashtags;
                        $phraseWithoutHash = substr($lowerCasePhrase, 1);
                        foreach ($cashtagObjects as $cashtagObject) {
                            $cashtag = $cashtagObject->tag;
                            $lowerCaseHashtag = strtolower($cashtag);
                            if ($lowerCaseHashtag === $phraseWithoutHash) {
                                return array("operation" => $subjectUserInfo['matchingphraseoperation'], "filtertype" => "matchingphrase",
                                    "filtercontent" => $phrase['phrase']);
                            }
                        }
                    }
                } else if (strpos((String) $userDescription, (String) $lowerCasePhrase) !== false) {
                    return array("operation" => $subjectUserInfo['matchingphraseoperation'], "filtertype" => "matchingphrase",
                        "filtercontent" => $phrase['phrase']);
                }
            }
        }
        if ($subjectUserInfo['nftprofilepictureoperation'] == "Block" || $subjectUserInfo['nftprofilepictureoperation'] == "Mute") {
            if ($objectUser->ext_has_nft_avatar) {
                return array("operation" => $subjectUserInfo['nftprofilepictureoperation'], "filtertype" => "nftprofilepictures",
                    "filtercontent" => null);
            }
        }
        if ($subjectUserInfo['urlsoperation'] == "Block" || $subjectUserInfo['urlsoperation'] == "Mute") {
            foreach ($urls as $url) {
                $urlHost = strtolower($url['url']);
                foreach ($userURLHosts as $userURLHost) {
                    if (isset($userURLHost) && (strpos((String) $urlHost, (String) $userURLHost) !== false)) {
                        return array("operation" => $subjectUserInfo['urlsoperation'], "filtertype" => "urls",
                            "filtercontent" => $url['url']);
                    }
                }
            }
        }
        if ($subjectUserInfo['cryptousernamesoperation'] == "Block" || $subjectUserInfo['cryptousernamesoperation'] == "Mute") {
            foreach ($regexes as $regex) {
                $userName = strtolower($objectUser->name);
                if (preg_match($regex['regex'], $userName)) {
                    return array("operation" => $subjectUserInfo['cryptousernamesoperation'], "filtertype" => "cryptousernames",
                        "filtercontent" => $regex['regex']);
                }
            }
        }
        return false;
    }

}

TwitterUsers::$logger = LogManager::getLogger("TwitterUsers");