<?php
	require("nzbp.php");
	$nzb  = new nzb;
	$from = "index.php?msg=Settings Saved.";
	
	//echo "<pre>";
	//print_r($_POST);
	//echo "</pre>";	
	
	if(isset($_POST["api"]))
		$nzb->Settings[1] = $_POST["api"];
		
	if(isset($_POST["autorename"]))
		$nzb->Settings[2] = $_POST["autorename"];	

	if(isset($_POST["from"]))
		$from = $_POST["from"];
	
	if(isset($_POST["tmpFolder"]))
		$nzb->Settings[3] = $_POST["tmpFolder"];
		
	if(isset($_POST["saveToFolder"]))
		$nzb->Settings[4] = $_POST["saveToFolder"];
		
	if(isset($_POST["MoveFolders"]))
		$nzb->Settings[5] = $_POST["MoveFolders"];
		
	if(isset($_POST["nzbSave"]))
		$nzb->Settings[6] = $_POST["nzbSave"];

		
	if(isset($_POST["nzbScanFolder"]))
		$nzb->Settings[7] = $_POST["nzbScanFolder"];	

	if(isset($_POST["cleardb"]))
		$nzb->Settings[8] = $_POST["cleardb"];

		
	if(isset($_POST["par2ScanFolder"]))
		$nzb->Settings[9] = $_POST["par2ScanFolder"];
		
	if(isset($_POST["unpack"]))
		$nzb->Settings[10] = $_POST["unpack"];	
		
	if($_POST["keeppar"])
		$nzb->Settings[11] = $_POST["keeppar"];	
		
	if($_POST["segments"])
		$nzb->Settings[12] = $_POST["segments"];
		
	if($_POST["nameonly"])
		$nzb->Settings[13] = $_POST["nameonly"];
		
	if(isset($_POST["filters"]))
		$nzb->Settings[14] = $_POST["filters"];		
		
		
	$nzb->Settings[2]  = ($nzb->Settings[2] =="on")?1:0;
	$nzb->Settings[6]  = ($nzb->Settings[6] =="on")?1:0;	
	$nzb->Settings[8]  = ($nzb->Settings[8] =="on")?1:0;
	$nzb->Settings[10] = ($nzb->Settings[10]=="on")?1:0;
	$nzb->Settings[11] = ($nzb->Settings[11]=="on")?1:0;
	$nzb->Settings[12] = ($nzb->Settings[12]=="on")?1:0;
	$nzb->Settings[13] = ($nzb->Settings[13]=="on")?1:0;
	
	$query = "UPDATE settings SET api='".$nzb->Settings[1]."', autorename=".$nzb->Settings[2].", tmpFolder='".$nzb->Settings[3]."', saveToFolder='".$nzb->Settings[4]."', MoveFolders='".$nzb->Settings[5]."', nzbSave=".$nzb->Settings[6].", nzbScanFolder='".$nzb->Settings[7]."', cleardb=".$nzb->Settings[8].", par2ScanFolder='".$nzb->Settings[9]."', unpack=".$nzb->Settings[10].", keeppar=".$nzb->Settings[11].", segments=".$nzb->Settings[12].", nameonly=".$nzb->Settings[13].", filters='".$nzb->Settings[14]."';";
	$result = mysql_query($query, DBLINK);
	//echo $query;
	
	header('Location: '.$from); 
?>
