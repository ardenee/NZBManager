<?php
include("nzbp.php");
require("index.inc");
$nzb   = new nzb;
$Types = array("nzb");

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";
echo "<body onload=\"displayRow('SettingsBody');\">\n";
echo " <table height=100%>\n";

echo DisplaySettings($nzb->Settings);

echo "  <tr class=title1>\n";
echo "   <td colspan=2><B>nzbPlanet Search</B></td>\n";
echo "  </tr>\n";
echo "  <form action=nzbsearch.php method=post>\n";	
echo "  <tr class=main1>\n";
echo "   <td>Search for:</td>\n";
echo "   <td>&nbsp;<input type=text name=\"searchstring\" size=50 max=50>&nbsp;<input type=\"submit\" value=Submit></td>\n";
echo "  </tr>\n";	
echo "  </form>\n";
echo "  <tr class=title1>\n";
echo "   <td colspan=2>NZB Scan Folder - ".$nzb->Settings[7]."</td>\n";
echo "  </tr>\n";
echo "  <tr class=main1>\n";
echo "   <td colspan=2 height=100% valign=top>";

echo DisplayFiles($nzb->Settings[7], $nzb, $Types);

echo "   </td>\n";
echo "  </tr>\n";
echo "  <tr class=title1 id=ignore>\n";
echo "   <td colspan=2>Database Files</td>\n";
echo "  </tr>\n";
echo "  <tr class=main1>\n";
echo "   <td colspan=2 height=100%>";

echo DisplayDBFiles($nzb);

echo "   </td>\n";
echo "  </tr>\n";
echo "  <tr class=title1 id=ignore>\n";
echo "   <td colspan=2>Upload nzb/torrent Files</td>\n";
echo "  </tr>\n";
echo "  <form action=\"upload.php\" method=\"post\"  enctype=\"multipart/form-data\">\n";
echo "  <tr class=main1>\n";
echo "   <td colspan=2 height=100%>";
echo "     <input type='file' name='files[]' multiple>\n";
echo "     <input type=checkbox name=saved>Upload to Saved Folder (Torrent Files)?\n";
echo "     <input type=Submit value=Upload>\n";
echo "   </td>\n";
echo "  </tr>\n";
echo "  </form>\n";
echo $footer;	
echo " </table>\n";
echo "</html>\n";
?>