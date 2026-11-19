<?php
require("nzbp.php");
require("renaming.inc");
$nzb = new nzb;

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo " </head>\n";

echo "<body>\n";
echo " <table width=100% cellspace=1 height=100%>\n";
echo "  <tr>\n";
echo "   <td colspan=2 class=title1>Renaming Scan Folder - ".$nzb->Settings[9]."</td>\n";
echo "  </tr>\n";
echo "  <tr>\n";
echo "   <td colspan=2 height=100% class=main1 valign=top>";

echo DisplayFolders($nzb, $nzb->Settings[9]);

echo "   </td>\n";
echo "  </tr>\n";
echo $footer;
echo " </table>";
echo "</html>";
?>