<?php
require("nzbp.php");
require("renamefiles.inc");
$nzb = new nzb;

if(isset($_GET["title"]))
	$title = $_GET["title"];

$newfiles = GetNewFiles($nzb, $title);

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";
echo "<body>\n";

echo " <table height=100%>\n";
echo "  <form name=rename id=rename method=post action=rename.php?from=renaming.php>";
echo "  <tr class=title1 id=ignore>\n";
echo "   <td colspan=3>Folder : ".$nzb->Settings[9].$title."</td>";
echo "  </tr>\n";
echo "  <tr class=title1 id=ignore>\n";
echo "   <td>Num</td>";
echo "   <td>Current Name</td>";
echo "   <td>New Name</td>";
echo "  <tr>\n";
	
$num=1;
	
foreach($newfiles as $key => $val)
{
	echo "  <tr class=main1>\n";
	echo "   <td><input type=checkbox id=\"$num\" checked name=\"oldname[".FilterOn($val)."]\" value=\"".NameOnly($val)."\"> $num</td>\n";
	echo "   <td>".NameOnly($val)."</td>\n";
	echo "   <td><input type=text name=\"newname[".FilterOn($val)."]\" size=50 value=\"".NameOnly(GetNewName($val, $title, $nzb->Settings[9]))."\"></td>\n"; 
	echo "  </tr>\n";
	$num++;
}
	
echo "	<tr class=main1 id=ignore>\n";
echo "   <td colspan=3><input type=button name=\"inverse\" onclick=\"checkedAll('rename');\" value=Inverse> <input type=submit value=Rename name=Submit></td>\n";
echo "  </tr>\n";	
echo "  </form>\n";
echo " <tr class=main1 id=ignore>\n";
echo "  <td height=100% colspan=3>&nbsp;</td>\n";
echo " </tr>\n";
echo $footer;	
echo " </table>";
?>