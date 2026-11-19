<?php
include("db.php");

$hash = "";
$file = "";
$from = "index.php";

if(isset($_GET["from"]))
	$from = $_GET["from"];

if(isset($_GET["hash"]))
	$hash = $_GET["hash"];
	
if(isset($_GET["file"]))
	$file = $_GET["file"];
	
if(strlen($hash)>0)
{
	$result = mysql_query("SELECT id FROM hashes WHERE hash='".$hash."';", DBLINK);	
	$h_id   = mysql_fetch_row($result);	

	mysql_query("DELETE FROM hashes WHERE id=".$h_id[0].";",       DBLINK);
	mysql_query("DELETE FROM files WHERE hashid=".$h_id[0].";",    DBLINK);	 
	mysql_query("DELETE FROM meta WHERE hashid=".$h_id[0].";",     DBLINK);	
	mysql_query("DELETE FROM segments WHERE hashid=".$h_id[0].";", DBLINK);
}

if(strlen($file)>0)
	@unlink($nzbScanFolder.$file);

header('Location: '.$from);
?>