<?
require_once("includes/adjudicator.php");
require_once("includes/backend.php");

//for information on what simulated annealing is, see: http://en.wikipedia.org/wiki/Simulated_annealing

/*
TODO for this file:
serious tuning of the SA algorithm (though it seems fairly ok already)
serious optimizing (speed) of the SA algorithm

present energy details in a nice matter

adjudicator history (uni's, other adjudicators) as a scoring factor

make a number of variables (for the energy) configurable by the user
    like: tunable different desired adjudicator averages for different debates

everything that is related to probability of making the break / winning

bin protection

number of adjudicators per debate could vary

allow for a report on manual changes too (score and messages)
technical: weave in messaging and scoring mechanisms.

team conflicts (next to already existing university conflicts)

introduce 'geography' (i.e. debates with similar points) into random_select?
*/

function get_average(&$list, $attr) {
    $sum = 0;
    foreach ($list as &$item)
        $sum += $item[$attr];
    return $sum / count($list);
}

function set_desired_averages(&$debates, $average) {
    foreach ($debates as &$debate)
        $debate['desired_average'] = $average;
}

function set_unequal_desired_averages(&$debates, &$adjudicators) {
    $average_adjudicator = get_average($adjudicators, 'ranking');
    $average_debate = get_average($debates, 'points');
    if ($average_debate == 0)
        $average_debate = 999; // irrelevant but cannot be 0
    foreach ($debates as &$debate) {
        $debate['desired_average'] = $average_adjudicator * 0.5 +
            ($average_adjudicator * 0.5 * $debate['points'] / $average_debate);
    }
}

function allocate_simulated_annealing(&$msg, &$details) {
    $nextround = get_num_rounds() + 1;
    mt_srand(0);
    $debates = temp_debates_foobar($nextround);
    $adjudicators = get_active_adjudicators();
    set_unequal_desired_averages($debates, $adjudicators);
    initial_distribution($debates, $adjudicators);
    actual_sa($debates);
    $energy = debates_energy($debates);
    $msg[] = "Adjudicator Allocation (SA) score is: $energy";
    $details = array_merge($details, debates_energy_details($debates));
    write_to_db($debates, $nextround);
}

function cmp_ranking($adjudicator_0, $adjudicator_1) {
    return $adjudicator_0['ranking'] - $adjudicator_1['ranking'];
}

function write_to_db($debates, $round) {
    //add some checks here...
    create_temp_adjudicator_table($round);
    foreach ($debates as &$debate) {
        usort($debate['adjudicators'], 'cmp_ranking');
        $chair = array_pop($debate['adjudicators']);
        mysql_query("INSERT INTO `temp_adjud_round_$round` " .
            "VALUES('{$debate['debate_id']}','{$chair['adjud_id']}','chair')");
        foreach ($debate['adjudicators'] as $adjudicator)
            mysql_query(
                "INSERT INTO `temp_adjud_round_$round` " .
                "VALUES('{$debate['debate_id']}','{$adjudicator['adjud_id']}','panelist')");
    }
}

function initial_distribution(&$debates, &$adjudicators) {
    $nr_debates = count($debates);
    $i = 0;
    while($adjudicator = array_pop($adjudicators)) {
        $debates[$i % $nr_debates]['adjudicators'][] = $adjudicator;
        $i++;
    }
}

function debate_energy(&$debate) {
    $result = 0;
    foreach($debate['adjudicators'] as $adjudicator)
        foreach($adjudicator['univ_conflicts'] as $conflict) 
            foreach ($debate['universities'] as $university)
                if ($conflict == $university) {
                    $result += 1000;
                }
    
    $adjudicators = $debate['adjudicators'];
    usort($adjudicators, 'cmp_ranking');
    $chair = array_pop($adjudicators);
    $result += 1 * (100 - $chair['ranking']);

    $result += pow(get_average($debate['adjudicators'], 'ranking') - $debate['desired_average'], 2);
    return $result;
}

function debate_energy_details(&$debate) {
    $result = array();

    foreach($debate['adjudicators'] as $adjudicator)
        foreach($adjudicator['univ_conflicts'] as $conflict) 
            foreach ($debate['universities'] as $university)
                if ($conflict == $university) {
                    $result[] = "1000: {$adjudicator['adjud_name']} has a conflict with univ_id '$conflict'";
                }
    
    $adjudicators = $debate['adjudicators'];
    usort($adjudicators, 'cmp_ranking');
    $chair = array_pop($adjudicators);
    $diff = 100 - $chair['ranking'];
    $penalty = 1 * ($diff);
    $result[] = "$penalty: Chair {$chair['adjud_name']} has $diff difference from 100.";

    $real = get_average($debate['adjudicators'], 'ranking');
    $desired_average = $debate['desired_average'];
    $penalty = pow($real - $desired_average, 2);
    $result[] = "$penalty: Debate '{$debate['debate_id']}' has desired average $desired_average but real average is $real";
    return $result;
}

function debates_energy(&$debates) {
    $result = 0;
    foreach ($debates as $debate)
        $result += debate_energy($debate);
    return $result;
}

function debates_energy_details(&$debates) {
    $result = array();
    foreach ($debates as $debate)
        $result = array_merge($result, debate_energy_details($debate));
    return $result;
}

function random_select(&$debates) {
    $i = mt_rand(0, count($debates) - 1);
    $debate = $debates[$i];
    $j = mt_rand(0, count($debate['adjudicators']) - 1);
    return array($i, $j);
}

function swap(&$debates, $one, $two) {
    $buffer = $debates[$one[0]]['adjudicators'][$one[1]];
    $debates[$one[0]]['adjudicators'][$one[1]] = $debates[$two[0]]['adjudicators'][$two[1]];
    $debates[$two[0]]['adjudicators'][$two[1]] = $buffer;
}

function actual_sa(&$debates) {
    $temp = 1.0;
    $best_energy = debates_energy($debates);
    $best_debates = $debates;
    $i = 0;
    while ($i < 50000) {
        do {
            $one = random_select($debates);
            $two = random_select($debates);
        } while ($one[0] == $two[0]);
        $before = debate_energy($debates[$one[0]]) + debate_energy($debates[$two[0]]);
        swap($debates, $one, $two);
        $after = debate_energy($debates[$one[0]]) + debate_energy($debates[$two[0]]);
        $diff = $after - $before;
        if (!throw_dice(probability($diff, $temp))) {
            swap($debates, $one, $two); //swap back
        }
        if ($diff < 0) { //better than prev
            $energy = debates_energy($debates);
            if ($energy < $best_energy) {
                $best_debates = $debates;
                $best_energy = $energy;
            }
        }
        $temp = decrease_temp($temp);
        $i++;
    }
    $debates = $best_debates;
}

function probability($diff, $temp) {
    if ($diff < 0)
        return 1;
    if ($diff >= 0)
        return 0.5 * $temp;
}

function throw_dice($probability) {
    $nr = 10000;
    $dice = mt_rand(0, $nr - 1);
    return ($dice <= $probability * $nr);
}

function decrease_temp($temp) {
    $alpha = 0.00001;
    return $temp * (1 - $alpha);
}

?>