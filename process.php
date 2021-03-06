<?php

require_once __DIR__.'/vendor/autoload.php';

set_include_path(".:/usr/share/php:./pear/share/pear");
require_once 'Mail/Mbox.php';
//use ZBateson\MailMimeParser\MailMimeParser;
//use ZBateson\MailMimeParser\Message;
//use ZBateson\MailMimeParser\Header\AddressHeader;

$parser = new PhpMimeMailParser\Parser();

$path = $argv[1];
//echo "Path=$path\n";
if (!$path && !file_exists($path)) {
  echo "Invalid input\n";
  die(1);
}

// read config file_exists
$jsonStr = file_get_contents("./config/config.json");
$config = json_decode($jsonStr);

if (is_dir($path)) {
  $filesCollection = array_merge(array_diff(scandir($path), array('..', '.')));
  $count = count($filesCollection);
  for ($i = 0 ; $i < $count; $i++) {
    $filesCollection[$i] = $path . DIRECTORY_SEPARATOR . $filesCollection[$i];
  }
} else {
  // just process single file
  $filesCollection = array();
  array_push($filesCollection, $path);
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
  "`previd` int(11) NULL,\n" .
  "`nextid` int(11) NULL,\n" .
  "`userid` int(11) NULL,\n" .
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
foreach ($filesCollection as $mboxFile) {
  echo "Processing file $mboxFile----------------------------------\n";
  $mbox = new Mail_Mbox($mboxFile);
  $res = $mbox->open();
  if ($res !== true) {
    echo $res . "\n";
  }
  echo "Num messages:" . $mbox->size() . "\n";
  for ($n = 0; $n < $mbox->size(); $n++) {
//    if ($n > 10) continue;
    echo "=============================\n";
    $rawMsg = $mbox->get($n);
    $parser->setText($rawMsg);

    $messageId = $parser->getHeader('Message-ID');
    $midp = explode(".", $messageId);
    if (count($midp) > 2) {
      $messageNum = $midp[2];     // <= messageNum
      echo "Message ID: $messageNum => $messageId\n";
      if (!is_numeric($messageNum)) {
        $messageNum = $lastMessageNumber + 1;
        $lastMessageNumber += 1;
        echo "*** We have a message ID problem ($messageId). Going to use $messageNum\n";
      } else {
        $lastMessageNumber = $messageNum;
      }
    } else {
      $messageNum = $lastMessageNumber + 1;
      $lastMessageNumber += 1;
      echo "*** We have a message ID problem ($messageId). Going to use $messageNum\n";
    }

    $subject = $parser->getHeader('subject'); // <= subject
    echo "Subject: $subject\n";
    $dateRaw = trim($parser->getHeader('date'));
    echo "Date: $dateRaw\n";
    $dateSql = makeSqlDataFromData($dateRaw);
    $body = $parser->getMessageBody('text');  // <= body

    $rawHeaderFrom = $parser->getHeader('from');
    $rawHeaderFrom = strtolower($rawHeaderFrom);
    $rawHeaderFrom = str_replace('"', '', $rawHeaderFrom);  // remove double quotes

    $email = extractEmail($rawHeaderFrom);
    $fromObf = obfuscateEmail($email, $rawHeaderFrom);
    echo "From: $email => $rawHeaderFrom\n";

    if (is_numeric($messageNum)) {
       if (in_array($messageNum, $message_ids)) {
         echo "****** $messageNum has already been used\n";
       } else {
           if (array_key_exists($rawHeaderFrom, $userIds)) {
               $uid = $userIds[$rawHeaderFrom];
           } else {
               $uid = addToUserTable($conn, $config->usertable, $rawHeaderFrom);
               if ($uid == 0) {
                   print_r($userIds);
                   die("Should not return a 0\n");
               }
               $userIds[$rawHeaderFrom] = $uid;
           }
           addToMsgTable($conn, $config->messagetable, $messageNum, $subject, $fromObf, $dateSql, $body, $uid);
       }
    } else {
      echo "****** $midp is not a valid id number\n";
    }

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
function addToMsgTable($connection, $msgtablename, $msgid, $subject, $from, $date, $body, $userid)
{
    global $userIds;
  echo " INS $msgid with U:$userid\n";
  if ($userid == 0) {
      print_r($userIds);
      die("We cannot use userid 0 for insertion\n");
  }
  $escSubj = $connection->real_escape_string($subject);
  $escBody = $connection->real_escape_string($body);

  $sql = "INSERT INTO $msgtablename VALUES ($msgid, '$date', '$escSubj', '$escBody', 0, 0, $userid)";
//  echo "\n  sql:" . substr($sql, 0, 160) . "\n\n";

  $res = $connection->query($sql);
  if (!$res) {
    echo 'ErrorMsg:' . $connection->error;
  }
  $insert_id = $connection->insert_id;
  echo (" msg insert:$insert_id\n");
  return $insert_id;
}
