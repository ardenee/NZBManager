<?php
require("nzbp.php");
include("movefiles.inc");
$nzb       = new nzb;
$newFolder = false;
$folder    = "";

if(count($_POST["files"])==0)
	header("Location: moving.php");

if(isset($_POST["folder"]))
	$folder = $_POST["folder"];		
	
if(isset($_POST["newfolder"]) and strlen($_POST["newfolder"])>0)
{
	mkdir(rtrim($folder, "/")."/".rtrim($_POST["newfolder"],"/"));
	$newFolder = true;
}

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";

if($newFolder)
	echo "<body onload=\"MoveClick($folder)\">\n";
else
	echo "<body>\n";

echo " <table onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
echo "  <tr class=title1>\n";
echo "   <td colspan=2>Move to Folder Selection</td>\n";
echo "  </tr>\n";
echo "   <form action=movefiles.php name=selectfolder id=selectfolder method=post>\n";
echo "    <input type=\"hidden\" id=\"newfolder\" name=\"newfolder\" value=\"\">\n";
echo "  <tr class=main1 id=\"ignore\">\n";
echo "    <input type=\"hidden\" id=\"folder\" name=\"folder\" value=\"$folder\">\n";
echo "   <td colspan=2 height=100% class=main1 valign=top>\n";

foreach($_POST["files"] as $key => $val)
	echo "    <input type=hidden name=\"files[]\" value=\"$val\">\n";
	
echo "  </form>\n";
	
FilesToMove($_POST["files"]);
	
if(strcontainsArray($folder, $nzb->Settings[5]))
	DisplayMoveToFolders($folder, $nzb);
else
	DisplayRootFolders($nzb->Settings[5]);	
	
echo "   <form action=move.php name=move id=move method=post>\n";
echo "    <input type=\"hidden\" id=\"folder\" name=\"folder\" value=\"$folder\">\n";

foreach($_POST["files"] as $key => $val)
	echo "    <input type=hidden name=\"files[]\" value=\"$val\">\n";
	
echo "    <input type=Submit value=\"Move to Here\">\n";
echo "    <input type=button name=button value=\"Create Folder\" onClick=\"CreateFolder()\">";
echo "   </td>\n";
echo "  </tr>\n";
echo "  </form>\n";
echo $footer;	
echo " </table>\n";
echo "</html>\n";
?>
