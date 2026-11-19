<?php
//include("db.php");

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>Dup Error!</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo " </head>\n";
echo " <table height=100%>\n";
echo "  <tr class=title1>\n"; 
echo "   <td colspan=2><a onclick=\"displayRow('1')\" style=\"cursor:hand; cursor:pointer\">Error!</a></td>\n";
echo "  </tr>\n";
echo "  <tr class=main1>\n";
echo "   <td colspan=2 class=l1>Try running the <a href=\"setup.php\">Setup</a> page to change the MySQL password.<BR><BR>Incountered an error: <pre>".mysql_error()."\n<BR>\n";

print_r(error_get_last());

echo "\n</pre></td>\n";
echo "  </tr>\n";	
echo "  <tr class=main1>\n";
echo "   <td colspan=2 height=100% class=l1 valign=top>Other things to check<BR><BR><b>/volume1/</b> is added to the open_basedir - Used to read/write nzb files.<BR><img src='php.png'>";
echo "   </td>\n";
echo "  </tr>\n";
echo $footer;
echo " </table>";
echo "</html>";

die();
?>