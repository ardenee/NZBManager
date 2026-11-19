<?php
	$from = "index.php";

	if(isset($_GET["from"]))
		$from = $_GET["from"];

	if(is_file($_GET["file"]))
		@unlink($_GET["file"]);

	header("Location: $from");
?>