<?php
require("db.php");

$hash = $_POST["hash"];

if(strlen($_POST["newtitle"])>0)
	mysql_query("UPDATE hashes SET title='".$_POST["newtitle"]."' WHERE hash='".$hash."';", DBLINK);

header('Location: index.php');
?>