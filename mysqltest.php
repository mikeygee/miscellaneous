<?php
	$db = new mysqli('localhost', 'root', 'root','nflive_db1112');
	if(mysqli_connect_errno())
		die("Connection error\n");
	$query = "select * from nfl_team";
	$result = $db->query($query);
	while($row = $result->fetch_assoc())
		print_r($row);
?>
