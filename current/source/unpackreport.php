<?php
require("unpackreport.inc");

$cmd      = "";
$log      = "";

if(isset($_GET["cmd"]))
	$cmd = urldecode($_GET["cmd"]);
if(isset($_POST["cmd"]))
	$cmd = $_POST["cmd"];
	
if(isset($_GET["log"]))
	$log = urldecode($_GET["log"]);	
if(isset($_POST["log"]))
	$log = $_POST["log"];	

$result       = GetUpdate($log); // last entry!!
$filedownload = "";

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 5.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>Unpack Report</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";		

echo " <table width=100% cellspace=1>\n";
echo "  <tr class=title1>\n";
echo "   <td colspan=3>Unpack Report</td>\n";
echo "  </tr>\n";
echo "  <tr class=title2>\n";
echo "   <td colspan=3>$cmd</td>\n"; 
echo "  </tr>\n";
echo " <tr class=main1>\n";
	
if(strcontains("All OK", $result))
{
	CheckForMoreFiles();	
}
else if(strcontains("Total errors:", $result) or strcontains("No files to extract", $result) or strcontains("ERROR: Bad archive", $result))
{
	$result = GetError($log); 
	echo "  <td colspan=3><pre>$result</pre></td>\n";
	echo " </tr>\n";	
	
	echo " <tr class=main1>\n";
	echo "  <td colspan=3>Try running a repair if there are <a href=\"par2.php\">PAR2</a> files.</td>\n";
	echo " </tr>\n";		
	unlink($log);
}
else
{	
	echo "  <td colspan=3><pre>$result</pre></td>\n";
	echo " </tr>\n";			
	echo "  <form action=unpackreport.php method=post name=unpack id=unpack>\n";
	echo "   <input type=hidden name=\"log\" value=\"".$log."\">\n";
	echo "   <input type=hidden name=\"cmd\" value=\"".htmlspecialchars($cmd)."\">\n";
		
	if(isset($_POST["files"]))
	{
		foreach($_POST["files"] as $key => $val)
			echo "   <input type=hidden name=\"files[]\" value=\"$val\">\n";	
	}		
		
	echo "  </form>\n";		
	echo "<body onload=\"ReSubmitReport('unpack')\">\n";					
}
echo "  <tr>\n";
echo "   <td colspan=3>Note! unzip and tar don't let the system know it's complete, please use the links below if the update above doesn't change for a period of time.</td>\n"; 
echo "  </tr>\n";
echo $footer;
echo "</table>\n";
?>