<?php
set_include_path(".:/usr/share/php:./pear/share/pear");
require_once 'Mail/Mbox.php';

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
    $filesCollection[$i] = $path . $filesCollection[$i];
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
 if ($config->resetTables) {
  $dropSql = "DELETE FROM " . $config->usertable . ";";
  $conn->query($dropSql);
  $dropSql = "DELETE FROM " . $config->messagetable + ";";
  $conn.query($dropSql);
}

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
    $message = $mbox->get($n);
  }
}
//MBoxParse(); // start parsing the mbox file(s)


$conn->close();
