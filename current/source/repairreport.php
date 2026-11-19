<?php
require("db.php");
require("repairreport.inc");

$cmd      = "";
$log      = "";

if(isset($_GET["cmd"]))
	$cmd = $_GET["cmd"]; // urldecode() ??
if(isset($_POST["cmd"]))
	$cmd = $_POST["cmd"];
	
if(isset($_GET["log"]))
	$log = $_GET["log"]; // urldecode() ??	
if(isset($_POST["log"]))
	$log = $_POST["log"];	
	
$result       = GetUpdate($log); // last entry!!
$filedownload = "";

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 5.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>Repair Report</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";	
	
echo " <table>\n";
echo "  <tr class=title1>\n";
echo "   <td colspan=3>Repair Report</td>\n";
echo "  </tr>\n";
echo "  <tr class=title2>\n";
echo "   <td colspan=3>$cmd</td>\n"; 
echo "  </tr>\n";
echo " <tr class=main1>\n";
	
if(strcontains("All files are correct, repair is not required.", $result) or strcontains("Repair complete.", $result))
{
	CheckForMoreFiles();
}
else if(strcontains("more recovery blocks to be able to repair.", $result) or strcontains("Main packet not found.", $result))
{	
	$result = GetError($log); 
	echo "  <td colspan=3><pre>$result</pre></td>\n";
	echo " </tr>\n";	
	
	$filedownload = LoadMorePar2Files($log);
}
else
{	
	echo "  <td colspan=3><pre>$result</pre></td>\n";
	echo " </tr>\n";	
				
	echo " <form action=repairreport.php method=post name=repair id=repair>\n";
	echo "  <input type=hidden name=\"log\" value=\"".$log."\">\n";
	echo "  <input type=hidden name=\"cmd\" value=\"".htmlspecialchars($cmd)."\">\n";
		
	if(isset($_POST["files"]))
	{
		foreach($_POST["files"] as $key => $val)
			echo "  <input type=hidden name=\"files[]\" value=\"$val\">\n";	
	}		
		
	echo " </form>\n";		
	echo "<body onload=\"ReSubmitReport('repair')\">\n";		
}

echo $filedownload;
echo $footer;
echo "</table>\n";
?>