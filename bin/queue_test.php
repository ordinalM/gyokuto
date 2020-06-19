<?php
/**
 * Test the queue system.
 */
require_once(__DIR__ . '/../src/GyokutoApp.class.php');

$g = new Gyokuto\App();

$qid = 'testqueue';
$q_file = $g->_getQueueFileFromId($qid);
unlink($q_file);

$g->addQueueItems($qid, range('a', 'z'));

var_dump($g->getQueueItems($qid, 10));
$g->removeQueueItems($qid, 10);
var_dump(file_get_contents($q_file));
$g->removeQueueItems($qid, 100);
var_dump(file_get_contents($q_file));
var_dump($g->getQueueItems($qid, 10));
