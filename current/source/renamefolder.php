<?php
require("nzbp.php");
require("renamefolder.inc");
$nzb     = new nzb;
$oldname = "";
$from = "renaming.php";

if(isset($_GET["oldname"]))
	$oldname = $_GET["oldname"];	
	
if(isset($_GET["from"]))
	$from = $_GET["from"];	
	
$path    = substr($oldname, 0, strripos($oldname, "/")+1);
$oldName = substr($oldname, strripos($oldname, "/")+1);
$newname = CleanUpName($oldName);

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo " </head>\n";
echo "<body>\n";

echo " <table height=100%>\n";
echo "  <form name=rename id=rename method=post action=rename.php>";
echo "  <tr class=title1>\n";
echo "   <td colspan=3>Folder : $oldname</td>";
echo "  </tr>\n";
echo "  <form action=rename.php method=post>\n";	
echo "   <input type=hidden name=from value=\"$from\">\n";
echo "  <tr class=main1>\n";
echo "   <td>Currnet Name:</td>\n";
echo "   <td class=l1><input type=text name=\"oldname[".FilterOn($oldname)."]\" value=\"".$oldName."\" size=60 readonly></td>\n";
echo "  </tr>\n";	
echo "  <tr class=main1>\n";	
echo "   <td>New Name:</td>\n";
echo "   <td class=l1><input type=text name=\"newname[".FilterOn($oldname)."]\" value=\"".$newname."\" size=60></td>\n";
echo "  </tr>\n";	
echo "  <tr class=main1>\n";
echo "  <td colspan=2><input type=\"reset\" value=Reset> <input type=\"submit\" value=Rename></td>\n";
echo "  </tr>\n";	
echo "  </form>\n";
echo " <tr class=main1 id=ignore>\n";
echo "  <td height=100% colspan=2>&nbsp;</td>\n";
echo " </tr>\n";
echo $footer;	
echo " </table>";
?>