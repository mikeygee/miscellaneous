<?php
// NBA score scraper

	include 'simple_html_dom.php';
	date_default_timezone_set('America/Los_Angeles');

	// returns array of score maps from NBA.com
	// if no arguments passed, current scores will be returned
	// pass date argument (yyyymmdd) to retrieve past scores
	function getNBAScores() {
		$date = (func_num_args() == 1) ? func_get_arg(0) : date('Ymd');
		$html = file_get_html("http://www.nba.com/gameline/$date");
		$awayTeam = $html->find('.nbaModTopTeamAw .nbaModTopTeamName');
		$homeTeam = $html->find('.nbaModTopTeamHm .nbaModTopTeamName');
		$awayScore = $html->find('.nbaModTopTeamAw .nbaModTopTeamNum');
		$homeScore = $html->find('.nbaModTopTeamHm .nbaModTopTeamNum');
		$status = $html->find('.nbaModTopStatus');
		
		$games = array();
		for($i = 0, $len = sizeof($awayTeam); $i < $len; $i++) {
			$at = strtoupper($awayTeam[$i]->innertext);
			$ht = strtoupper($homeTeam[$i]->innertext);
			$as = (int) $awayScore[$i]->innertext;
			$hs = (int) $homeScore[$i]->innertext;
			$st = str_get_html($status[$i]->innertext);
			$final = $st->find('.nbaFnlStatTx');
			$live = $st->find('.nbaLiveStatTx');
			$hasFinal = $final ? $final[0]->innertext : false;
			$hasLive = $live ? $live[0]->innertext : false;
			$winner = $hasFinal && !$hasLive ? (($hs > $as) ? $ht : $at) : NULL;
			array_push($games, array(
				'awayTeam' => $at,
				'homeTeam' => $ht,
				'awayScore' => $as,
				'homeScore' => $hs,
				'winner' => $winner
			));
		}

		return $games;
	}

	// returns array of current odds maps from covers.com
	// teams id'd by city name, for LA - trim down to team name
	function getNBAOdds() {
		$html = file_get_html("http://www.covers.com/odds/basketball/nba-spreads.aspx");
		$awayTeam = $html->find('.team_away strong');
		$homeTeam = $html->find('.team_home strong');
		$total = $html->find('.covers_top .line_top');
		$spread = $html->find('.covers_top .covers_bottom');

		$odds = array();
		for($i = 0, $len = sizeof($awayTeam); $i < $len; $i++) {
			$at = preg_replace('/(L\.A\.\s)/','', $awayTeam[$i]->innertext);
			$ht = preg_replace('/(L\.A\.\s)/','', trim($homeTeam[$i]->innertext, '@'));
			$t = (float) $total[$i]->innertext;
			$s = (float) $spread[$i]->innertext;
			array_push($odds, array(
				'awayTeam' => $at,
				'homeTeam' => $ht,
				'total' => $t,
				'spread' => $s,
				'favorite' => ($s < 0) ? $ht : (($s > 0) ? $at : 'pick')
			));
		}
		return $odds;
	}
	
	// scrape scores and write them to picksmash database, return true on success
	function updateNBAScores() {
		$date = (func_num_args() == 1) ? func_get_arg(0) : date('Ymd');

		// connect to db
		$db = new mysqli('localhost', 'root', 'root','picksmash');
		if(mysqli_connect_errno())
			die($db->error);

		// scrape the scores
		$scores = getNBAScores($date);

		foreach($scores as $val) {
			// store values
			$ht = $val['homeTeam'];
			$at = $val['awayTeam'];
			$hs = $val['homeScore'];
			$as = $val['awayScore'];
			$winner = $val['winner'];

			// get home team id
			$query = "select id from sport_team where sport_id = 1 and short_name = '$ht'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$hid = $row['id'];

			// get away team id
			$query = "select id from sport_team where sport_id = 1 and short_name = '$at'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$aid = $row['id'];

			// if game is over, set winner id
			$wid = $winner ? ($hs > $as ? $hid : $aid) : 0;

			// update game table
			$query = "update game set home_score = $hs, away_score = $as, winner_id = $wid where home_id = $hid and away_id = $aid and season_id in (select id from season where sport_id = 1) and DATE_FORMAT(game_date,'%Y%m%d') = '$date'";
			if($result = $db->query($query)) {
				$db->commit();
			}
			else
				die($db->error);
		}
		return sizeof($scores);
	}
	
	// scrape currently listed odds and write them to picksmash database, return true on success
	function updateNBAOdds() {
		$date = date('Ymd');
		// connect to db
		$db = new mysqli('localhost', 'root', 'root','picksmash');
		if(mysqli_connect_errno())
			die($db->error);
		// scrape the odds
		$odds = getNBAOdds();
		
		foreach($odds as $val) {
			// store values
			$ht = $val['homeTeam'];
			$at = $val['awayTeam'];
			$total = $val['total'];
			$spread = $val['spread'];

			// get home team id, workaround for the two LA teams - use team name instead of city
			$query = ($ht == 'Lakers' || $ht == 'Clippers') ? "select id from sport_team where mascot = '$ht'" : "select id from sport_team where city = '$ht'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$hid = $row['id'];

			// get away team id
			$query = ($at == 'Lakers' || $at == 'Clippers') ? "select id from sport_team where mascot = '$at'" : "select id from sport_team where city = '$at'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$aid = $row['id'];

			// set favorite, if spread is 0, set favorite id to 0, update spread to abs value
			$fav = ($spread < 0) ? $hid : (($spread > 0) ? $aid : 0);
			$spread = abs($spread);

			// update game table
			$query = "update game set point_spread = $spread, over_under = $total, favorite = $fav where home_id = $hid and away_id = $aid and season_id in (select id from season where sport_id = 1) and DATE_FORMAT(game_date,'%Y%m%d') = '$date'";
			if($result = $db->query($query)) {
				$db->commit();
			}
			else
				die($db->error);
		}
		return sizeof($odds);
	}
	/*------------
	usage: php nba_scrape.php [options]
		-scores : print current scores to console
		-scores [date] : print scores from a specific date (format = yyyymmdd)
		-updateScores : write current scores to picksmash database
		-updateScores [date] : write scores for a specific week to picksmash database
		-odds : print current odds to console
		-updateOdds : write current odds to the database
		-updateAll : write current scores and odds to the database

	use full path to correct php binary
	------------*/
	
	if(isset($argv)) {
		if($argv[1] == '-scores') {
			if(sizeof($argv) == 2)
				$scores = getNBAScores();
			else if(sizeof($argv) == 3)
				$scores = getNBAScores($argv[2]);
			print_r($scores);
		}
		else if($argv[1] == '-odds') {
			$odds = getNBAOdds();
			print_r($odds);
		}
		else if($argv[1] == '-updateScores') {
		   if(sizeof($argv) == 3) {
			   $result = updateNBAScores($argv[2]);
			   echo "$result scores updated";
			}
			else {
			   $result = updateNBAScores();
			   echo "$result scores updated";
			}
		} 
		else if($argv[1] == '-updateOdds') {
		   $result = updateNBAOdds();
		   echo "$result odds updated";
		}
		else if($argv[1] == '-updateAll') {
		   $result = updateNBAScores();
		   echo "$result scores updated\n";
		   $result = updateNBAOdds();
		   echo "$result odds updated\n";
		}
	}

/*-------Test Queries--------
select g.game_date, t1.full_name as away_team, t2.full_name as home_team, g.point_spread, g.over_under, t3.full_name as favorite, g.away_score, g.home_score, t4.full_name as winner
from game g
left outer join sport_team t1 on g.away_id = t1.id
left outer join sport_team t2 on g.home_id = t2.id
left outer join sport_team t3 on g.favorite = t3.id
left outer join sport_team t4 on g.winner_id = t4.id
where
DATE_FORMAT(game_date,'%Y%m%d') = '20120327'

update game
set point_spread = 0, over_under = 0, favorite = NULL
where season_id in (select id from season where sport_id = 1) and DATE_FORMAT(game_date,'%Y%m%d') = '20120327'

update game
set away_score = 0, home_score = 0, winner = NULL
where season_id in (select id from season where sport_id = 1) and DATE_FORMAT(game_date,'%Y%m%d') = '20120327'
-----------------------------*/
?>

