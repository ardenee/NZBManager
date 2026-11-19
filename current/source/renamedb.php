<?php
require("db.php");

$hash     = $_GET["hash"];
$result   = mysql_query("SELECT title FROM hashes WHERE hash='".$hash."';", DBLINK);
$oldtitle = mysql_fetch_row($result); 

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo " </head>\n";

echo " <table height=100%>\n";
echo "  <tr class=title1>\n";
echo "   <td><B>Rename Title - Database</B></td>\n";
echo "  </tr>\n";
echo "  <form action=renametitle.php method=post>\n";
echo "   <input type=hidden name=hash value=\"$hash\">\n";
echo "  <tr class=main1>\n";
echo "   <td><input type=text name=\"oldtitle\" value=\"".$oldtitle[0]."\" size=100 readonly></td>\n";
echo "  </tr>\n";	
echo "  <tr>\n";	
echo "   <td><input type=text name=\"newtitle\" value=\"".$oldtitle[0]."\" size=100></td>\n";
echo "  </tr>\n";	
echo "  <tr>\n";
echo "  <td><input type=\"submit\" value=Rename></td>\n";
echo "  </tr>\n";	
echo "  </form>\n";
echo "  <tr class=main1>\n";
echo "   <td colspan=2 height=100%>&nbsp;</td>\n";
echo "  </tr>\n";	
echo $footer;
echo " </table>\n";
?>