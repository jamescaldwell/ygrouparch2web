<?php
$path = $argv[1];
//echo "Path=$path\n";
if (!$path && !file_exists($path)) {
  echo "Invalid input\n";
  die(1);
}

// read config file_exists
$jsonStr = file_get_contents("./config/config.json");
$config = json_decode($jsonStr);
$filesCollection = array();
if (is_dir($path)) {
  $tempDir = scandir($path);
  echo "Num files " . count($tempDir) . "\n";
  foreach ($tempDir as $tf) {
    if (strpos($tf, ".json") !== FALSE && strpos($tf, "raw") === FALSE) {
      if (strpos($tf, ".") == 0)
        echo "$tf shouldn't be in there\n";
      array_push($filesCollection, $path . $tf);
    }
  }
} else {
  // just process single file
  echo "Path needs to be a directory\n";
  die("Usage: php -f singleproc.php <path>");
}

echo "Connect to db..." . $config->database;
// open database connection
$conn = new mysqli(
  $config->db_host,
  $config->db_user,
  $config->db_password,
  $config->database
);
if ($conn->connect_error) {
  die("Connect Error (" . $conn->connect_errno . ") " . $conn->connect_error);
}

$sqlUserTable = "CREATE TABLE IF NOT EXISTS `" . $config->usertable . "` (\n" .
 "`id` int(11) NOT NULL auto_increment, \n" .
 "`username` varchar(250)  NOT NULL default '',\n" .
 "PRIMARY KEY  (`id`),\n" .
 "UNIQUE INDEX `username_UNIQUE` (`username` ASC),\n" .
 "INDEX `username_INDEX` (`username` ASC))\n" .
 "ENGINE = InnoDB\n" .
 "DEFAULT CHARACTER SET = utf8;";

 $sqlMsgTable = "CREATE TABLE IF NOT EXISTS `" . $config->messagetable . "` (\n" .
  "`id` int(11) NOT NULL, \n" .
  "`date` DATETIME NULL,\n" .
  "`subject` VARCHAR(250) NULL,\n" .
  "`body` TEXT NULL,\n" .
  "`userid` int(11) NULL,\n" .
  "`topicid` int(11) NULL,\n" .
  "`previd` int(11) NULL,\n" .
  "`nextid` int(11) NULL,\n" .
  "PRIMARY KEY (`id`),\n" .
  "INDEX `message_DATE` (`date` ASC),\n" .
  "INDEX `fk_ymessage_user_idx` (`userid` ASC),\n" .
  "CONSTRAINT `fk_ymessage_user`\n" .
  "  FOREIGN KEY (`userid`)\n" .
  "  REFERENCES `" . $config->usertable . "` (`id`)\n" .
  "  ON DELETE NO ACTION\n" .
  "  ON UPDATE NO ACTION)\n" .
  "ENGINE = InnoDB\n" .
  "DEFAULT CHARACTER SET = utf8;";


$result = $conn->query($sqlUserTable);
$result = $conn->query($sqlMsgTable);

 // if flag is true then reset tables
 echo "Reset Tables = " . $config->resetTables . "\n";
 if ($config->resetTables) {
   echo "Deleting table data...\n";
  $dropSql = "DELETE FROM " . $config->usertable;
  $conn->query($dropSql);
  $dropSql = "DELETE FROM " . $config->messagetable;
  $conn->query($dropSql);
}

$message_ids = array(); // keep track of message ids
$userIds = array();     // associative array of rawEmail => uid of table
$lastMessageNumber = 0;
// parse through each mbox file_exists
foreach ($filesCollection as $emailFile) {
  echo "Processing file $emailFile----------------------------------\n";
  $json = json_decode(file_get_contents($emailFile));
  $messageId = $json->msgId;
  echo "Message ID: $messageId\n";
  $subject = $json->subject;
  echo "Subject: $subject\n";
  $dateSql = date("Y-m-d H:i:s", $json->postDate);
  $body = $json->messageBody;  // <= body
  $email = html_entity_decode(strtolower($json->from));
  $author = $json->authorName;
  echo "From: $email => $author\n";
//  $fromObf = obfuscateEmail($email, $rawHeaderFrom);
  $fromObf = $email;
  $topicId = $json->topicId;
  if (!is_numeric($topicId)) {
      $topicId = 0;
  }
  $nextId = $json->nextInTopic;
    if (!is_numeric($nextId)) {
        $nextId = 0;
    }
  $prevId = $json->prevInTopic;
    if (!is_numeric($prevId)) {
        $prevId = 0;
    }

  if (is_numeric($messageId)) {
     if (in_array($messageId, $message_ids)) {
       echo "****** $messageId has already been used\n";
     } else {
         if (array_key_exists($email, $userIds)) {
             $uid = $userIds[$email];
         } else {
             $uid = addToUserTable($conn, $config->usertable, $email);
             if ($uid == 0) {
                 print_r($userIds);
                 die("Should not return a 0\n");
             }
             $userIds[$email] = $uid;
         }
         addToMsgTable($conn, $config->messagetable, $messageId, $subject, $fromObf, $dateSql, $body, $uid, $topicId, $prevId, $nextId);
     }
  } else {
    echo "****** $messageId is not a valid id number\n";
  }
}

$conn->close();

//--------------------------------------------------------------
// change the email so it will not able to be scraped by robots
//--------------------------------------------------------------
function extractEmail($emailFrom) {
  $matches = array();
  $xEmail = $emailFrom;
  $pattern = '/[a-z0-9_\-\+\.]+@[a-z0-9\-]+\.([a-z]{2,4})(?:\.[a-z]{2})?/i';
  preg_match_all($pattern, $emailFrom, $matches);

  if (count($matches) > 0) {
    $xEmail = $matches[0][0];
  }
  return $xEmail;
}

function obfuscateEmail($emailaddress, $emailRaw) {
  $emailScramble = scrambleEmail($emailaddress);
  $newEmail = str_replace($emailaddress, $emailScramble, $emailRaw);
  return $newEmail;
}

// scramble the email address with x's
function scrambleEmail($emailAddr) {
  $halves = explode("@", $emailAddr);
  if (count($halves) > 1) {
    $hs = str_split($halves[1]);
    $i = 0;
    foreach ($hs as $hc) {
      if ($hc != '@' || $hc != '.') {
        $hs[$i] = 'x';
      }
      $i++;
    }
    $newHalf = implode('', $hs);
    $scramble = $halves[0] . '@' . $newHalf;
  } else {
    $scramble = $emailAddr;
  }
  return $scramble;
}

// Mon, 19 Apr 1999 15:00:50 +0200  => 1999-04-19 15:00:50
function makeSqlDataFromData($datestr) {
    $ygDateFormats = array(
        "D, j M Y G:i:s O",
        "D, j M Y G:i:s O e",
        "D, j M Y G:i:s O (e)",
        "D, j M Y G:i:s O (T)",
        "D, j M Y G:i:s e",
        "D, j M Y G:i e",
        "D j M Y G:i:s O",
        "j M Y G:i:s O",
        "D, j M Y G:i:s",
    );
    $idx = 0;
    foreach ($ygDateFormats as $df) {
        $date = DateTime::createFromFormat($df, $datestr);
        if (!$date) {
//            echo "  failure with format $idx\n";
//            print_r(date_get_last_errors());
            $idx++;
        } else {
            break;
        }
    }
    if (!$date) {
        die ("Bad date $datestr\n");
    }
    $datetime = $date->format("Y-m-d H:i:s");
    return $datetime;
}
//--------------------------------------------------------------
//--------------------------------------------------------------
// add username to table and return id
function addToUserTable($connection, $usertablename, $userName)
{
    $escUserName = $connection->real_escape_string($userName);
    $sql = "INSERT INTO $usertablename (username) VALUES ('$escUserName')";
    $res = $connection->query($sql);
    if (!$res) {
        echo 'ErrorUser:' . $connection->error;
    }
    $insert_id = $connection->insert_id;
    echo (" user $userName insert:$insert_id\n");
    return $insert_id;
}
function addToMsgTable($connection, $msgtablename, $msgid, $subject, $from, $date, $body, $userid, $topicId, $prevId, $nextId)
{
    global $userIds;
  echo " INS $msgid with U:$userid\n";
  if ($userid == 0) {
      print_r($userIds);
      die("We cannot use userid 0 for insertion\n");
  }
  $escSubj = $connection->real_escape_string($subject);
  $escBody = $connection->real_escape_string($body);

  $sql = "INSERT INTO $msgtablename VALUES ($msgid, '$date', '$escSubj', '$escBody', $userid, $topicId, $prevId, $nextId)";
//  echo "\n  sql:" . substr($sql, 0, 160) . "\n\n";

  $res = $connection->query($sql);
  if (!$res) {
    echo 'ErrorMsg:' . $connection->error;
  }
  $insert_id = $connection->insert_id;
  echo (" msg insert:$insert_id\n");
  return $insert_id;
}
