<?php
// https://github.com/tbblake/myScripts/tree/main/dhcpPihole
// sortOrder:
//	0 - ascending
//	1 - descending
// sortField:
//	1 - mac address
//	3 - name
//	4 - expiration (0 works as well, re-mapped to 4)
//	5 - ip (2 works as well, re-mapped to 5)
// fmt:
//	0 - output html table
//	1 - output text table
//	2 - output json table
//	3 - output csv table
//	4 - the raw dhcp.leases file
// noDate - supresses date in output

$leaseFile="/etc/pihole/dhcp.leases";

if(!is_readable($leaseFile)) {
	print("Can't read $leaseFile");
	exit(1);
}

$noDate=array_key_exists("noDate",$_GET);

if(array_key_exists("fmt",$_GET)) {
	$fmtParam=$_GET["fmt"];
	switch($fmtParam) {
		case 0:
			$fmt=0; // html
			break;
		case 1:
			$fmt=1; // text
			break;
		case 2:
			$fmt=2; // json
			break;
		case 3:
			$fmt=3; // csv
			break;
		case 4:
			$fmt=4; // raw
			break;
	}
}
if(array_key_exists("sortField",$_GET)) {
	$sortFieldParam=$_GET["sortField"];
} else {
	$sortFieldParam=4;
}
if(array_key_exists("sortOrder",$_GET)) {
	$sortOrderParam=$_GET["sortOrder"];
} else {
	$sortOrderParam=0;
}

if(array_key_exists("macAddress",$_GET)) {
	$macAddress=$_GET["macAddress"];
} else {
	$macAddress=False;
}

$dateFormat="m/d/y h:i:sa";
$humanFormats=[0,1]; // html and text formats are for human consumption

if(isset($fmt)) { // we have a format set, otherwise print the outer HTML
	# read in the leases,format and sort
	$data=file_get_contents($leaseFile);
	$leaseLines = explode(PHP_EOL, $data);
	if($leaseLines[count($leaseLines)-1] == "") {  // remove empty entry at the end
		array_pop($leaseLines); 
	}
	$leases=[];
	# in each line remove the uid
	# and push the date and ip in
	# numeric format onto the end
	# if a displayable method is picked
	# (html or text) reformat the date
	# in column 0
	foreach ($leaseLines as $line) {
		$lease=explode(" ",$line);
		array_pop($lease);
		array_push($lease,$lease[0]);
		array_push($lease,ip2long($lease[2]));
		if(in_array($fmt,$humanFormats)) { // human readable formats, column 0 should be human readable
			$lease[0]=date($dateFormat,$lease[0]);
		}
		if(!$macAddress || $macAddress == $lease[1]) {
			array_push($leases,$lease);
		}
	}

	switch($sortOrderParam) {
		case 0: // ascending
			$sortOrderFlag=SORT_ASC;
			break;
		case 1: // descending
			$sortOrderFlag=SORT_DESC;
			break;
		default: // ascending
			$sortOrderFlag=SORT_ASC;
			break;
	}

	switch($sortFieldParam) {
		case 0: // expiration, re-map to our hidden sorting column 4
			$sortFieldParam=4;
			$sortField=$sortFieldParam;
			$sortType=SORT_NUMERIC;
			break;
		case 1: // mac
			$sortField=$sortFieldParam;
			$sortType=SORT_FLAG_CASE;
			break;
		case 2: // ip, re-map to our hidden sorting column 5
			$sortFieldParam=5;
			$sortField=$sortFieldParam;
			$sortType=SORT_NUMERIC;
			break;
		case 3: // name
			$sortField=$sortFieldParam;
			$sortType=SORT_NATURAL|SORT_FLAG_CASE;
			break;
		case 4: // expiration
			$sortField=$sortFieldParam;
			$sortType=SORT_NUMERIC;
			break;
		case 5: // ip
			$sortField=$sortFieldParam;
			$sortType=SORT_NUMERIC;
			break;
		default: // same as "expires"
			$sortType=SORT_NUMERIC;
			$sortField = 4;
			break;
	}
	# extract the column we'll sort by, then sort the main array using that column
	$sortKeys=array_column($leases,$sortField);
	array_multisort($sortKeys,$sortType,$sortOrderFlag,$leases);
	
	# print the table according to $fmt
	switch($fmt) {
		case 0: // html
			if(!$noDate) {
				print("<table id='date'><tr><td>");
				print date($dateFormat);
				print("</td></tr></table><br>\n");
			}
			print("<table id='dhcp'>\n");
			print("<tr>");
			print("<th onclick='sortTable(4,1)'>Expires</th>");
			print("<th onclick='sortTable(1,0)'>MAC</th>");
			print("<th onclick='sortTable(5,1)'>IP</th>");
			print("<th onclick='sortTable(3,0)'>Name</th>");
			print("<th class='hidden'>hiddenExpires</th>");
			print("<th class='hidden'>hiddenIP</th>");
			print("</tr>\n");
			foreach ($leases as $lease) {
				print("<tr>");
				print("<td>".$lease[0]."</td>");
				print("<td>".$lease[1]."</td>");
				print("<td>".$lease[2]."</td>");
				print("<td>".$lease[3]."</td>");
				print("<td class='hidden'>".$lease[4]."</td>");
				print("<td class='hidden'>".$lease[5]."</td>");
				print("</tr>\n");
			}
			print("</table>\n");
			break;
		case 1: // text
			header("Content-Type: text/plain");
			if(!$noDate) {
				print(date($dateFormat)."\n\n");
			}
			$strLengths=array_fill(0,4,0);
			foreach ($leases as $lease) { // find longest string in each field
				for($i=0;$i<4;$i++) {
					if(strlen($lease[$i]) > $strLengths[$i]) {
						$strLengths[$i]=strlen($lease[$i]);
					}
				}
			}
			foreach ($leases as $lease) {
				for($i=0;$i<4;$i++) {
					$formatSpec="%-".$strLengths[$i]."s  ";
					printf($formatSpec,$lease[$i]);
				}
				print("\n");
			}
			break;
		case 2: // json
			// create new $out array with the date, and a sub-list
			// of all the leases
			header("Content-Type: application/json");
			$out=array("data" => array());
			if(!$noDate) {
				$out["date"] = date("U");
			}
			$keyFields=["expire","mac","ip","name"];
			// step through the leases, push on an associative array
			// of the 4 important fields (leave out the two fields we used for sorting)
			foreach ($leases as $lease) {
				$usefulInfo=array_slice($lease,0,4); # slice out the first four fields
				$useInfoAssoc=array_combine($keyFields,$usefulInfo); # combine them with headers into an associate array
				array_push($out["data"],$useInfoAssoc); # push onto a larger array to display
			}
			print(json_encode($out));
			break;
		case 3: // csv
			header("Content-Type: text/csv");
			$keyFields=["expire","mac","ip","name"];
			if(!$noDate) {
				$dateField = date("U");
				array_push($keyFields,"date");
			}
			$out = fopen('php://output', 'w');
			fputcsv($out, $keyFields);
			// step through the leases, push on an associative array
			// of the 4 important fields (leave out the two fields we used for sorting)
			
			foreach ($leases as $lease) {
				$usefulInfo=array_slice($lease,0,4); # slice out the first four fields
				if(!$noDate) {
					array_push($usefulInfo,$dateField);
				}
				fputcsv($out,$usefulInfo);
			}
			fclose($out);
			break;
		case 4: // raw
			header("Content-Type: text/plain");
			print($data);
			break;
	}
} else {
	?>
	<!doctype html>
	<!-- https://github.com/tbblake/myScripts/tree/main/dhcpPihole -->
	<html>
	<head>
	<title>Lease Status</title>
	<link href="data:image/x-icon;base64,AAABAAEAEBAAAAEACABoBQAAFgAAACgAAAAQAAAAIAAAAAEACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAC1AF4ArgBlAKwAZgCtAGYArABnAK0AZwCxAWMArgFmAKsBaACsAWgApQlvAKUKcACxC24Aow1zAJ8QdACaFnkAmBh8AJUcfwCVHX8Alh5+ALYdeAC7HngAqR98AIsohwCTLIgAhjGRAL43iADAOIgAhTuUAHo/mgDBQI0AwkGNAHtEnADCQY4AdkegAIxHnQDDRpEAw0eRAMZJkwBxT6QAxkyUAMdQlwDKUpcA01KVAGlVsQDCV5wAyVebAM9XmgBhXLMAzlqcANBdnQDRXZ0Apl+nAF9hugB6ZLMAuWKlAH9msgBRecEAUHnHAF5/tQA5j90AOZbhADua5AA/rO0ATLXrAEq89QBTw/YAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0M+Pj5DQ0NDQ0M+Pj5DQ0NCIA4XPj5DQz4+HA8RPkNCHQYFAwYNLDUZAAMFBAs+QggFAwMDAwUFBAMDAwQBLD0VDBJAQCIBBRBAQDoDBBM+GhsYO0AnAQUKOUAwAQcsQCMmJRQEAgICAgICAgIJPkNANisuKSQfHiElKCoWPkNDQz5ANDcxMzIvLTQ4PkNDQ0NDQ0I+QT48Pz4+Q0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ///AAD//wAA//8AAMfjAACBgQAAAAAAAAAAAAAAAAAAAAAAAAAAAACAAQAAwAMAAPAPAAD//wAA//8AAP//AAA=" rel="icon" type="image/x-icon" />
	<script>
		let updateTimeSeconds=5;
		let updateTime=updateTimeSeconds*1000;
		let gSortField=4; // by default we're gonna sort by expiration
		let gSortOrder=1; // in descending order
		setInterval(updateStatus,updateTime);
		// blatantly stolen (borrowed?) from https://www.w3schools.com/howto/howto_js_sort_table.asp
		function sortTable(n,h) { // h(how) 0 - alpha  1 - numeric   dir 0 - asc, 1 - desc
			var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
			table = document.getElementById("dhcp");
			switching = true;
			// Set the sorting direction to ascending:
			dir = 0;
			/* Make a loop that will continue until
			no switching has been done: */
			while (switching) {
				// Start by saying: no switching is done:
				switching = false;
				rows = table.rows;
				/* Loop through all table rows (except the
				first, which contains table headers): */
				for (i = 1; i < (rows.length - 1); i++) {
					// Start by saying there should be no switching:
					shouldSwitch = false;
					/* Get the two elements you want to compare,
					one from current row and one from the next: */
					x = rows[i].getElementsByTagName("TD")[n];
					y = rows[i + 1].getElementsByTagName("TD")[n];
					/* Check if the two rows should switch place,
					based on the direction, asc or desc: */
					if (dir == 0 && h == 0) {
						if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) {
							// If so, mark as a switch and break the loop:
							shouldSwitch = true;
							break;
						}
					} else if (dir == 0  && h == 1) {
						if (Number(x.innerHTML) > Number(y.innerHTML)) {
							shouldSwitch = true;
							break;
						}
					} else if (dir == 1 && h == 0) {
						if (x.innerHTML.toLowerCase() < y.innerHTML.toLowerCase()) {
							// If so, mark as a switch and break the loop:
							shouldSwitch = true;
							break;
						}
					} else if (dir == 1 && h == 1) {
						if (Number(x.innerHTML) < Number(y.innerHTML)) {
							shouldSwitch = true;
							break;
						}
					}
				}
				if (shouldSwitch) {
					/* If a switch has been marked, make the switch
					and mark that a switch has been done: */
					rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
					switching = true;
					// Each time a switch is done, increase this count by 1:
					switchcount++;
				} else {
					/* If no switching has been done AND the direction is 0,
					set the direction to 1 and run the while loop again. */
					if (switchcount == 0 && dir == 0) {
						dir = 1;
						switching = true;
					}
				}
			}
			gSortField=n;
			gSortOrder=dir;
		}
		function updateStatus() {
			var xhttp = new XMLHttpRequest();
			xhttp.onreadystatechange = function() {
				if (this.readyState == 4 && this.status == 200) {
					document.getElementById("status").innerHTML = this.responseText;
				}
			};
			xhttp.open("GET", "<?php print($_SERVER['SCRIPT_NAME']);?>?fmt=0&sortOrder="+gSortOrder+"&sortField="+gSortField, true);
			xhttp.send();
		}
		updateStatus();
	</script>
	<style>
		table, th, td {
			font-family: monospace;
			border-collapse: collapse;
			border: 0px;
			padding: 0px 5px;
			text-align: left;
		}

		#dhcp th {
			background-color: #444444;
			color: white;
		}

		#dhcp tr:nth-child(even) {
			background-color: #f2f2f2;
		}

		#dhcp tr:hover {
			background-color: #ddd;
		}
		
		.hidden {
			display: none;
		}
	</style>
	</head>
	<body>
	<div id="status">
	</body>
	</html>
	<?php
}
?>
