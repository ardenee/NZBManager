<?php

if(is_file($_GET["file"]))
	@unlink($_GET["file"]);

header('Location: index.php');
?>