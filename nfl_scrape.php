<?php
// NFL score and odds scraper

	include 'simple_html_dom.php';

	// returns array of score maps from NFL.com
	// if no arguments passed, current scores will be returned
	// only final scores are returned due to in-progress games using Javascript
	// pass year and week arguments to retrieve past scores
	function getNFLScores() {
		if(func_num_args() == 2) {
			$year = func_get_arg(0);
			$week = func_get_arg(1);
		}
		if(isset($year) && isset($week))
			$html = file_get_html("http://www.nfl.com/scores/$year/REG$week");
		else
			$html = file_get_html("http://www.nfl.com/scores");
		$time = $html->find('.time-left');
		$awayTeam = $html->find('.away-team .team-name a');
		$homeTeam = $html->find('.home-team .team-name a');
		$awayScore = $html->find('.away-team .total-score');
		$homeScore = $html->find('.home-team .total-score');
		
		$games = array();
		for($i = 0, $len = sizeof($awayTeam); $i < $len; $i++) {
			$tm = $time[$i]->innertext;
			$at = $awayTeam[$i]->innertext;
			$ht = $homeTeam[$i]->innertext;
			$as = (int) $awayScore[$i]->innertext;
			$hs = (int) $homeScore[$i]->innertext;
			$winner = ($tm == 'FINAL') ? (($hs > $as) ? $ht : $at) : NULL;
			array_push($games, array(
				'time' => $tm,
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
	// teams id'd by city name, for NY - trim down to team name, also trimmed period out of St. Louis to match db
	function getNFLOdds() {
		$html = file_get_html("http://www.covers.com/odds/football/nfl-spreads.aspx");
		$awayTeam = $html->find('.team_away strong');
		$homeTeam = $html->find('.team_home strong');
		$total = $html->find('.vegas_top .line_top a');
		$spread = $html->find('.vegas_top .vegas_bottom a');

		$odds = array();
		for($i = 0, $len = sizeof($awayTeam); $i < $len; $i++) {
			$at = preg_replace('/(N\.Y\.\s)|\./','', $awayTeam[$i]->innertext);
			$ht = preg_replace('/(N\.Y\.\s)|\./','', trim($homeTeam[$i]->innertext, '@'));
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
	
	// scrape scores and write them to nflive database, return true on success
	function updateScores() {
		if(func_num_args() == 2) {
			$year = func_get_arg(0);
			$week = func_get_arg(1);
		}

		// connect to db
		$db = new mysqli('localhost', 'root', 'root','nflive_db');
		if(mysqli_connect_errno())
			die($db->error);

		// scrape the scores
		if(isset($year) && isset($week))
			$scores = getNFLScores($year, $week);
		else 
			$scores = getNFLScores();

		foreach($scores as $val) {
			// store values
			$ht = $val['homeTeam'];
			$at = $val['awayTeam'];
			$hs = $val['homeScore'];
			$as = $val['awayScore'];
			$time = $val['time'];

			// get home team id
			$query = "select nfl_team_id from nfl_team where mascot = '$ht'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$hid = $row['nfl_team_id'];

			// get away team id
			$query = "select nfl_team_id from nfl_team where mascot = '$at'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$aid = $row['nfl_team_id'];

			// set winner if game is over
			if($time == 'FINAL')
				$winner = ($hs > $as) ? $hid : $aid;
			else
				$winner = 0;

			// update game table
			$query = "update game set home_score = $hs, away_score = $as, winner_id = $winner where home_team_id = $hid and away_team_id = $aid";
			if($result = $db->query($query)) {
				$db->commit();
			}
			else
				die($db->error);
		}
		return true;
	}
	
	// scrape odds and write them to nflive database, return true on success
	function updateOdds() {
		// connect to db
		$db = new mysqli('localhost', 'root', 'root','nflive_db1112');
		if(mysqli_connect_errno())
			die($db->error);
		// scrape the odds
		$odds = getNFLOdds();
		
		foreach($odds as $val) {
			// store values
			$ht = $val['homeTeam'];
			$at = $val['awayTeam'];
			$total = $val['total'];
			$spread = $val['spread'];

			// get home team id, workaround for the two NY teams
			$query = ($ht == 'Giants' || $ht == 'Jets') ? "select nfl_team_id from nfl_team where mascot = '$ht'" : "select nfl_team_id from nfl_team where city = '$ht'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$hid = $row['nfl_team_id'];

			// get away team id
			$query = ($at == 'Giants' || $at == 'Jets') ? "select nfl_team_id from nfl_team where mascot = '$at'" : "select nfl_team_id from nfl_team where city = '$at'"; 
			if(!($result = $db->query($query)))
				die($db->error);
			$row = $result->fetch_assoc();
			$aid = $row['nfl_team_id'];

			// set favorite, if spread is 0, set favorite id to 0, update spread to abs value
			$fav = ($spread < 0) ? $hid : (($spread > 0) ? $aid : 0);
			$spread = abs($spread);

			// update game table
			$query = "update game set point_spread = $spread, over_under = $total, favorite = $fav where home_team_id = $hid and away_team_id = $aid";
			if($result = $db->query($query)) {
				$db->commit();
			}
			else
				die($db->error);
		}
		return true;
	}
	/*------------
	usage: nfl_scrape.php [options]
		-scores : print current scores to console
		-scores [year] [week] : print scores from a specific week
		-odds : print current odds to console
		-updateOdds : write current odds to nflive database
		-updateScores : write current scores to nflive database
		-updateScores [year] [week] : write scores for a specific week to nflive database
		-updateAll : write current scores and odds to nflive database
	------------*/
	
	if(isset($argv)) {
		if($argv[1] == '-scores') {
			if(sizeof($argv) == 2)
				$scores = getNFLScores();
			else if(sizeof($argv) == 4)
				$scores = getNFLScores($argv[2], $argv[3]);
			print_r($scores);
		}
		else if($argv[1] == '-odds') {
			$odds = getNFLOdds();
			print_r($odds);
		}
		else if($argv[1] == '-updateScores') {
		   if(sizeof($argv) == 4) {
			   updateScores($argv[2], $argv[3]);
			   echo "Scores updated\n";
			}
			else {
			   updateScores();
			   echo "Scores updated\n";
			}
		} 
		else if($argv[1] == '-updateOdds') {
		   updateOdds();
		   echo "Odds updated\n";
		}
		else if($argv[1] == '-updateAll') {
		   updateScores();
		   echo "Scores updated\n";
		   updateOdds();
		   echo "Odds updated\n";
		}
	}

/*-------Test Queries--------
SELECT g.week_no, t1.full_name as away_team, t2.full_name as home_team, g.point_spread, g.over_under, t3.full_name as favorite
FROM game g
left outer join nfl_team t1 on g.away_team_id = t1.nfl_team_id
left outer join nfl_team t2 on g.home_team_id = t2.nfl_team_id
left outer join nfl_team t3 on g.favorite = t3.nfl_team_id
where g.week_no = 1

SELECT g.week_no, t1.full_name as away_team, g.away_score, t2.full_name as home_team, g.home_score, t3.full_name as winner
FROM game g
left outer join nfl_team t1 on g.away_team_id = t1.nfl_team_id
left outer join nfl_team t2 on g.home_team_id = t2.nfl_team_id
left outer join nfl_team t3 on g.winner_id = t3.nfl_team_id
where g.week_no = 1

update game
set home_score=NULL, away_score=NULL, winner_id=NULL
where week_no = 1

update game
set point_spread=NULL, over_under=NULL, favorite=NULL
where week_no = 1
-----------------------------*/
?>

