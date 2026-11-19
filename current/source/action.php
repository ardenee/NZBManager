<?php
	require("db.php");
	require("action.inc");
	$count = GetRepairFileCount("worker.txt");
	@unlink("worker.txt");
	$file = "";

	if(isset($_GET["file"]))
		$file = urldecode($_GET["file"]);
	else if(isset($_POST["file"]))
		$file = urldecode($_POST["file"]);
	
	if($_GET["action"]=="repair" or $_POST["action"]=="repair")
	{
		echo "<script>\n";
		echo "window.open(\"report.php?type=Repair&file=".urlencode($file)."\",\"\",\"toolbar=no,location=no,location=no,scrollbars=no,resizable=no,width=500,height=20,left=50,top=50\")\n"; 
		echo "</script>\n";		
		echo "<body onload=\"javascript:window.location='repair.php?file=".urlencode($file)."';\">";
	}
	else if($_GET["action"]=="unpack" or $_POST["action"]=="unpack")
	{		
		echo "<script>\n";
		echo "window.open(\"report.php?type=Unpack&count=$count&file=".urlencode($file)."\",\"\",\"toolbar=no,location=no,location=no,scrollbars=no,resizable=no,width=500,height=20,left=50,top=50\")\n"; 
		echo "</script>\n";		
		echo "<body onload=\"javascript:window.location='unpack.php?file=".urlencode($file)."';\">";
	}
?>