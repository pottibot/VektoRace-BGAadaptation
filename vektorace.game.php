<?php
 /**
  *------
  * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
  * VektoRace implementation : © <Pietro Luigi Porcedda> <pietro.l.porcedda@gmail.com>
  * 
  * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
  * See http://en.boardgamearena.com/#!doc/Studio for more information.
  * -----
  */

require_once(APP_GAMEMODULE_PATH.'module/table/table.game.php');
/* require_once('modules/VektoraceOctagon.php');
require_once('modules/VektoraceVector2.php');
require_once('modules/VektoracePoint2.php'); */

require_once('modules/VektoraceGameElement.php');
require_once('modules/VektoracePoint2.php');
require_once('modules/VektoraceOctagon2.php');
require_once('modules/VektoraceVector2.php');
require_once('modules/VektoracePitwall.php');
require_once('modules/VektoraceCurve.php');
require_once('modules/VektoraceCurb.php');

class VektoRace extends Table {
    
    //+++++++++++++++++++++//
    // SETUP AND DATA INIT //
    //+++++++++++++++++++++//
    #region setup

	function __construct() {
        parent::__construct();
        
        // GAME.PHP GLOBAL VARIABLES HERE
        self::initGameStateLabels( array(
            "turn_number" => 10,
            "last_curve" => 11,
            "number_of_laps" => 100,
            "circuit_layout" => 101,
            "map_boundaries" => 102
        ));        
	}
	
    // getGameName: basic utility method used for translation and other stuff. do not modify
    protected function getGameName() {
        return "vektorace";
    }	

    // setupNewGame: called once, when a new game is initialized. this sets the initial game state according to the rules
    protected function setupNewGame( $players, $options=array()) {

        // -- LOAD TRACK
        self::loadTrackPreset(); // custom function to set predifined track model

        $values = [];
        foreach (self::getObjectListFromDb("SELECT id FROM game_element WHERE entity = 'curve'", true) as $curveNum) {

            $curb = new VektoraceCurb($curveNum);
            ['x'=>$x, 'y'=>$y] = $curb->getCenter()->coordinates();
            $dir = $curb->getDirection();

            $values[] = "($curveNum,'curb',$x,$y,$dir)";
        }

        $values = implode($values,',');
        self::DbQuery("INSERT INTO game_element (id, entity, pos_x, pos_y, orientation) VALUES ".$values);

        // --- INIT PLAYER DATA ---
         
        // Set the colors of the players with HTML color code
        // The default below is red/green/blue/orange/brown
        // The number of colors defined here must correspond to the maximum number of players allowed for the gams
        $gameinfos = self::getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
        $values = array();

        foreach( $players as $player_id => $player ) {
            $color = array_shift( $default_colors );
            $values[] = "('".$player_id."','$color','".$player['player_canal']."','".addslashes( $player['player_name'] )."','".addslashes( $player['player_avatar'] )."')";

            self::DbQuery("INSERT INTO penalities_and_modifiers (player) VALUES ($player_id)"); // INIT PENALITIES AND MODIFIERS TABLE (just put player id everything else is set to the default value false) 
        }

        $sql .= implode( $values, ',' );
        self::DbQuery( $sql );

        $sql = "UPDATE player
                SET player_turn_position = player_no";
        self::DbQuery($sql);        

        self::reattributeColorsBasedOnPreferences( $players, $gameinfos['player_colors'] );
        self::reloadPlayersBasicInfos();

        // --- INIT GLOBAL VARIABLES ---
        self::setGameStateInitialValue('turn_number', 0 );
        self::setGameStateInitialValue('last_curve', self::getUniqueValueFromDb("SELECT count(id) FROM game_element WHERE entity='curve'"));
        self::setGameStateInitialValue('number_of_laps', $this->gamestate->table_globals[100]);
        self::setGameStateInitialValue('circuit_layout', $this->gamestate->table_globals[101]);
        self::setGameStateInitialValue('map_boundaries', $this->gamestate->table_globals[102]);
        
        // --- INIT GAME STATISTICS ---
        // table
        self::initStat( 'table', 'turns_number', 0 ); 

        // players
        self::initStat('player', 'turns_number', 0 );
        self::initStat('player', 'pole_turns', 0 );
        self::initStat('player', 'surpasses_number', 0 );
        self::initStat('player', 'pitstop_number', 0 );
        self::initStat('player', 'brake_number', 0 );
        self::initStat('player', 'tire_used', 0 );
        self::initStat('player', 'nitro_used', 0 );
        self::initStat('player', 'attMov_performed', 0 );
        self::initStat('player', 'attMov_suffered', 0 );
        self::initStat('player', 'average_gear', 0 );
        self::initStat('player', 'boost_number', 0 );
        // self::initStat('player', 'curve_quality', 0 );
        
        


        // --- SETUP INITIAL GAME STATE ---
        $sql = "INSERT INTO game_element (entity, id, orientation)
                VALUES ";
        
        $values = array();
        foreach( $players as $player_id => $player ) {
            $values[] = "('car',".$player_id.",".self::getUniqueValueFromDB("SELECT orientation FROM game_element WHERE entity='pitwall'").")"; // empty brackets to appends at end of array
        }
        
        $sql .= implode( $values, ',' );
        self::DbQuery( $sql );

        // --- ACTIVATE FIRST PLAYER ---
        $this->activeNextPlayer();
    }

    // getAllDatas: method called each time client need to refresh interface and display current game state.
    //              should extract all data currently visible and accessible by the callee client (self::getCurrentPlayerId(). which is very different from active player)
    //              [!!] in vektorace, no information is ever hidder from players, so there's no use in discriminate here.
    protected function getAllDatas() {
        $result = array();
    
        $current_player_id = self::getCurrentPlayerId();

        $sql = "SELECT player_id id, player_score score, player_turn_position turnPos, player_current_gear currGear, player_tire_tokens tireTokens, player_nitro_tokens nitroTokens, player_lap_number lapNum
                FROM player ";
        $result['players'] = self::getCollectionFromDb( $sql );
  
        $result['game_element'] = self::getObjectListFromDb( "SELECT * FROM game_element" );

        $result['octagon_ref'] = VektoraceGameElement::getOctagonMeasures();

        $sql = "SELECT player, NoShiftDown push, DeniedSideLeft leftShunk, DeniedSideRight rightShunk, BoxBox boxbox, NoShiftUp brake, CarStop `stop`
                FROM penalities_and_modifiers";
        $result['penalities_and_modifiers'] = self::getCollectionFromDb($sql);

        return $result;
    }

    /*
        getGameProgression:
        
        Compute and return the current game progression.
        The number returned must be an integer beween 0 (=the game just started) and
        100 (= the game is finished or almost finished).
    
        This method is called each time we are in a game state with the "updateGameProgression" property set to true 
        (see states.inc.php)
    */

    // getGameProgression: compute and return game progression (0-100). called by state which are supposed determine an advancement in the game
    function getGameProgression() {
        
        $firstPlayer = self::getPlayerTurnPosNumber(1);
        $playerLap = self::getPlayerLap($firstPlayer);
        $playerCurve = self::getPlayerCurve($firstPlayer);

        $lapStep = 100/self::getGameStateValue('number_of_laps');
        $curveStep = $lapStep/self::getGameStateValue('last_curve');
        $zoneStep = $curveStep/8;

        $lapPorgress = $playerLap * $lapStep;
        $curveProgress = ($playerCurve['number']-1) * $curveStep;
        $zoneProgress = max(($playerCurve['number']-1) * $zoneStep, 0);

        $progress = round($lapPorgress + $curveProgress + $zoneProgress);
        return min($progress,100);
    }

    #endregion

    //+++++++++++++++++++//
    // UTILITY FUNCTIONS //
    //+++++++++++++++++++//
    #region utility

    // [general purpose function that controls the game logic]

    // test: test function to put whatever comes in handy at a given time
    function test() {

        /* $oct = new VektoraceOctagon2(new VektoracePoint2(800,200), 0);
        $vector = new VektoraceVector2(new VektoracePoint2(-475,660), 4, 4);
        $pitwall = new VektoracePitwall(new VektoracePoint2(0,0),4);
        $curve = new VektoraceCurve(new VektoracePoint2(260,150),6);
        $curb = new VektoraceCurb(1);
        $point = new VektoracePoint2(0,200);

        $allVs = [
            'oct' => $oct->getVertices(),
            'vector' => $vector->getVertices(),
            'pitwall' => $pitwall->getVertices(),
            'curve' => $curve->getVertices(),
            'curb' => $curb->getVertices(),
            'points' => [$point]
        ];

        $allVs['vector'] = array_merge(...$allVs['vector']);
        $allVs['pitwall'] = array_merge(...$allVs['pitwall']);

        foreach ($allVs as &$objVs) {
            foreach ($objVs as &$v) {
                $v = $v->coordinates();
            } unset($v);
        } unset($objVs);

        self::notifyAllPlayers('allVertices','',$allVs);
        self::consoleLog([
            'vectorCollision' => $vector->collidesWith($curb, 0.5),
        ]); */
    }

    function mapVertices() {
        $siz = VektoraceGameElement::getOctagonMeasures()['size'];
        $off = 29;
        $off2 = 3;

        $tl = new VektoracePoint2(-11.5*$siz-$off, 16*$siz+$off);
        $tr = new VektoracePoint2(22*$siz+$off2, 16*$siz+$off);
        $bl = new VektoracePoint2(-11.5*$siz-$off, -2*$siz-$off);
        $br = new VektoracePoint2(22*$siz+$off2, -2*$siz-$off);

        $ret = [
            $tl->coordinates(),
            $tr->coordinates(),
            $bl->coordinates(),
            $br->coordinates(),
        ];
        self::notifyAllPlayers('allVertices','',['points'=>$ret]);
    }

    // consoleLog: debug function that uses notification to log various element to js console (CAUSES BGA FRAMEWORK ERRORS)
    function consoleLog($payload) {
        self::notifyAllPlayers('logger','i have logged',$payload);
    }

    function allVertices() {
        $ret = array();

        foreach (self::getObjectListFromDb("SELECT * FROM game_element") as $element) {
            switch ($element['entity']) {
                case 'car':
                    $car = new VektoraceOctagon2(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation']);
                    $ret['car '.$element['id']] = $car->getVertices();
                    break;

                case 'curve':
                    $curve = new VektoraceCurve(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation']);
                    $ret['curve '.$element['id']] = $curve->getVertices();
                    break;

                case 'curb':
                    $curb = new VektoraceCurb($element['id']);
                    $ret['curb '.$element['id']] = $curb->getVertices();
                    break;

                case 'pitwall':
                    $pitwall = new VektoracePitwall(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation']);
                    $ret['pitwall'] = array_merge(...$pitwall->getVertices());
                    break;

                case 'gearVector':
                case 'boostVector':
                    $vector = new VektoraceVector(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation'],$element['id']);
                    $ret[$element['entity'].' '.$element['id']] = array_merge(...$vector->getVertices());
                    break;
            }
        }

        foreach ($ret as &$element) {
            foreach ($element as &$vertex) {
                $vertex = $vertex->coordinates();
            } unset($vertex);
        } unset($element);

        self::notifyAllPlayers('allVertices','',$ret);
        return;
        
        foreach (self::getObjectListFromDb("SELECT * FROM game_element") as $element) {
            switch ($element['entity']) {
                case 'car':
                    if ($element['pos_x']!=null && $element['pos_y']!=null) {
                        $car = new VektoraceOctagon2(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation']);
                        $vertices = $car->getVertices();

                        foreach ($vertices as &$v) {
                            $v = $v->coordinates();
                        } unset($v);

                        $ret['car'.' '.$element['id']] = $vertices;
                    }
                    break;

                case 'curve':
                    $curve = new VektoraceCurve(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation']);
                    $vertices = $curve->getVertices();

                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v);

                    $ret['curve'.' '.$element['id']] = $vertices;
                    break;

                case 'pitwall':
                    $pitwall = new VektoracePitwall(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation']);

                    $vertices = array_merge(...$pitwall->getVertices());

                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v);

                    $ret['pitwall'] = $vertices;
                    break;

                default: // vectors and boosts
                    $vector = new VektoraceVector2(new VektoracePoint2($element['pos_x'],$element['pos_y']),$element['orientation'],$element['id']);

                    $vertices = array_merge(...$vector->getVertices());

                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v);

                    $ret[$element['entity'].' '.$element['id']] = $vertices;
                    break;
            }
        }

        self::notifyAllPlayers('allVertices','',$ret);
    }

    function testOvertake($p1, $p2) {
        $p1car = self::getPlayerCarOctagon(self::getPlayerTurnPosNumber($p1));
        $p2car = self::getPlayerCarOctagon(self::getPlayerTurnPosNumber($p2));

        self::consoleLog([
            'p1 overtakes p2' => $p1car->overtake($p2car),
            'p2 overtakes p1' => $p2car->overtake($p1car),
        ]);
    }
    
    function switchTurnPos($p1, $p2) {
        $p1id = self::getPlayerTurnPosNumber($p1);
        $p2id = self::getPlayerTurnPosNumber($p2);

        self::DbQuery("UPDATE player SET player_turn_position = $p2 WHERE player_id = $p1id");
        self::DbQuery("UPDATE player SET player_turn_position = $p1 WHERE player_id = $p2id");

        return;
    }

    function moveAllCars() {
        $x = 1500;
        $y = 0;
        $dir = 4;


        $allCars = self::getCollectionFromDb("SELECT id, pos_x x, pos_y y, orientation dir FROM game_element WHERE entity = 'car'");

        foreach ($allCars as $id => $car) {
            $newX = $car['x'] + $x;
            $newY = $car['y'] + $y;

            self::DbQuery("UPDATE game_element SET pos_x = $newX, pos_y = $newY, orientation = $dir WHERE id = $id");
        }
    }

    function assignCurve($n, $zone = 1) {
        
        self::DbQuery("UPDATE player SET player_curve_number = $n, player_curve_zone = $zone");
    }

    /* function isBoxBoxPlayerPos($p) {
        $query = self::getUniqueValueFromDb("SELECT BoxBox FROM penalities_and_modifiers WHERE player =".self::getPlayerTurnPosNumber($p));

        self::consoleLog(array(
            'boolval' => boolval($query),
            'val' => $query,
            'isnull' => is_null($query)
        ));
    } */

    // loadTrackPreset: sets DB to match a preset of element of a test track
    function loadTrackPreset() {

        switch (self::getGameStateValue('circuit_layout')) {
            case 1:
                self::DbQuery(
                    "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                        VALUES ('pitwall',10,0,0,4),
                               ('curve',1,-500,450,5),
                               ('curve',2,-500,1105,3),
                               ('curve',3,1645,1100,1),
                               ('curve',4,1645,450,7)"

                );
                break;

            case 2:
                self::DbQuery(
                    "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                        VALUES ('pitwall',10,0,0,4),
                               ('curve',1,-500,450,5),
                               ('curve',2,-500,1100,3),
                               ('curve',4,1645,450,7)"
                );
                break;

            case 3:
                self::DbQuery(
                    "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                        VALUES ('pitwall',10,0,0,4),
                               ('curve',1,-500,450,5),
                               ('curve',2,1645,1100,1),
                               ('curve',3,1645,450,7)"
                );
                break;

            case 4:
                self::DbQuery(
                    "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                        VALUES ('pitwall',10,0,0,4),
                               ('curve',1,-500,450,5),
                               ('curve',2,580,900,2),
                               ('curve',3,1645,450,7)"
                );
                break;
        }
    }

    function getPlayerCurve($id) {
        $curve = self::getObjectFromDb("SELECT player_curve_number num, player_curve_zone zon FROM player WHERE player_id = $id");
        $curveNum = intval($curve['num']);

        return [
            'number' => $curveNum,
            'zone' => $curve['zon'],
            'next' => max(1, ($curveNum+1) % (self::getGameStateValue('last_curve')+1))
        ];
    }

    function getCurveObject($n) {
        $curve = self::getObjectFromDb("SELECT id, pos_x x, pos_y y, orientation dir FROM game_element WHERE entity = 'curve' AND id = $n");
        return new VektoraceCurve(new VektoracePoint2($curve['x'], $curve['y']), $curve['dir']);
    }

    function isPlayerAfterLastCurve($id) {
        $playerCurve = self::getPlayerCurve($id);
        return $playerCurve['number'] == self::getGameStateValue('last_curve') && $playerCurve['zone'] > 4;
    }

    /* function isPlayerBoxBox($id) {

    } */

    function isPlayerRaceFinished($id) {
        return self::getUniqueValueFromDb("SELECT FinishedRace FROM penalities_and_modifiers WHERE player = $id");
    }

    function getLastFixedPos() {
        $i;
        for ($i=self::getPlayersNumber()-2; $i > 0; $i--) {
            if (self::isPlayerRaceFinished(self::getPlayerTurnPosNumber($i)))
                break;
        }

        return $i;
    }

    function getPlayerLap($id) {
        return self::getUniqueValueFromDb("SELECT player_lap_number FROM player WHERE player_id = $id");
    }

    // return player turn position given its id
    function getPlayerTurnPos($id) {

        $sql = "SELECT player_turn_position
                FROM player
                WHERE player_id = $id";
        return self::getUniqueValueFromDb( $sql );
    }

    // returns player with turn position number $n (names can be a lil confusing)
    function getPlayerTurnPosNumber($n) {

        $sql = "SELECT player_id
                FROM player
                WHERE player_turn_position = $n";

        return self::getUniqueValueFromDb( $sql );
    }

    // returns turn position number of player after $id in the custon turn order (as in, the one used for the current game round)
    function getPlayerAfterCustom($id) {
        $playerTurnPos = self::getPlayerTurnPos($id);
        $next = $playerTurnPos + 1;
        if ($next > self::getPlayersNumber()) $next = 1;

        return self::getPlayerTurnPosNumber($next);
    }

    // getPlayerCarOctagon: returns VektoraceOctagon object created at center pos, given by the coordinates of the players car, according to DB
    function getPlayerCarOctagon($id) {

        $sql = "SELECT pos_x, pos_y, orientation
                FROM game_element
                WHERE entity = 'car' AND id = $id";

        $ret = self::getObjectFromDB($sql);
        return new VektoraceOctagon2(new VektoracePoint2($ret['pos_x'],$ret['pos_y']), $ret['orientation']);
    }

    // getPlayerCurrentGear: returns player's current gear
    function getPlayerCurrentGear($id) {

        $sql = "SELECT player_current_gear
                FROM player
                WHERE player_id = $id";

        $ret = self::getUniqueValueFromDB($sql);
        return $ret;
    }
    
    // return vector object of the latest placed vector. can be gear or boost
    function getPlacedVector($type = 'gear') {

        $sql = "SELECT id, pos_x, pos_y, orientation
                FROM game_element
                WHERE entity = '".$type."Vector'";

        $ret = self::getObjectFromDB($sql);
        if (empty($ret)) return null;
        return new VektoraceVector2(new VektoracePoint2($ret['pos_x'],$ret['pos_y']), $ret['orientation'], $ret['id']);
    }

    function getPitwall() {
        $pw = self::getObjectFromDb("SELECT pos_x x, pos_y y, orientation dir FROM game_element WHERE entity = 'pitwall'");
        return new VektoracePitwall(new VektoracePoint2($pw['x'], $pw['y']), $pw['dir']);
    }

    // returns player tire and nitro tokens
    function getPlayerTokens($id) {

        $sql = "SELECT player_tire_tokens tire, player_nitro_tokens nitro 
                FROM player
                WHERE player_id = $id";
            
        return self::getObjectFromDB($sql);
    }

    // (called at end of round) calculates new turn order based on current car positions
    function newTurnOrder() {

        // get all cars pos from db
        $sql = "SELECT player_id id, player_turn_position turnPos
                FROM player";

        $allPlayers = $oldOrder = self::getCollectionFromDB($sql, true);

        self::consoleLog(['keys'=>implode(array_keys($allPlayers),','), 'values'=>implode(array_values($allPlayers),',')]);

        uasort($allPlayers, function ($p1, $p2) {

            $p1turnPos = $p1;
            $p2turnPos = $p2;

            $p1 = self::getPlayerTurnPosNumber($p1);
            $p2 = self::getPlayerTurnPosNumber($p2);

            $car1 = self::getPlayerCarOctagon($p1);
            $car2 = self::getPlayerCarOctagon($p2);

            $player1 = self::getPlayerNameById($p1);
            $player2 = self::getPlayerNameById($p2);
            self::trace("// COMPARING $player1 AND $player2");
            self::dump("// $player1 CAR", $car1);
            self::dump("// $player2 CAR", $car2);
            
            $p1curve = self::getPlayerCurve($p1)['number'];
            $p2curve = self::getPlayerCurve($p2)['number'];

            $p1lap = self::getPlayerLap($p1);
            $p2lap = self::getPlayerLap($p2);

            // check lap
            $lapComp = $p1lap <=> $p2lap; // if lap less or greater then, return result
            if ($lapComp != 0) return $lapComp;
            else {
                self::trace("// $p1turnPos equal lap $p2turnPos");
                // if equal lap, check curves
                $curveComp = $p1curve <=> $p2curve; // if lap less or greater then, return result
                if ($curveComp != 0) return $curveComp;
                else {
                    self::trace("// $p1turnPos equal curve $p2turnPos");
                    // if equal curves, check for actual overtaking
                    if ($car1->overtake($car2)) return 1; // if overtaking happens, car is greater than, otherwise is less
                    else {
                        self::trace("// $p1turnPos doesn't surpass $p2turnPos");
                        if ($p1turnPos < $p2turnPos && !$car2->overtake($car1)) return 1; // else, if car is not surpassed buy other AND turn position is higher (thus lower), this car is still greater
                        else return -1;
                    }
                }
            }
        });

        // self::consoleLog(['old'=>implode($oldOrder,','), 'new'=>implode($allPlayers,',')]);

        //$allPlayers = array_reverse($allPlayers, true);

        $isChanged = ($oldOrder === $allPlayers)? false : true;

        $lastFixedPos = self::getLastFixedPos();

        if ($isChanged) {
            $i = 1;
            foreach($allPlayers as $id => &$turnPos) {
                $turnPos = self::getPlayersNumber()+1 - ($lastFixedPos + $i);
                
                $sql = "UPDATE player
                        SET player_turn_position = $turnPos
                        WHERE player_id = $id";
                self::DbQuery($sql);

                $oldPos = $oldOrder[$id];
                $surpasses = $oldPos - $turnPos;
                if ($surpasses>0) self::incStat($surpasses,'surpasses_number',$id);

                $i++;
            } unset($player);
        }

        self::consoleLog(['keys'=>implode(array_keys($allPlayers),','), 'values'=>implode(array_values($allPlayers),',')]);

        return array('list'=>$allPlayers, 'isChanged'=>$isChanged);
    }

    function withinMapBoundaries($oct) {
        $boundariesOpt = self::getGameStateValue('map_boundaries');

        if ($boundariesOpt == 1) return true;

        $siz = VektoraceGameElement::getOctagonMeasures()['size'];

        $off = 29;
        $off2 = 3;
         
        $mapX = [-11.5*$siz-$off, 22*$siz+$off2];
        $mapY = [-2*$siz-$off, 16*$siz+$off];

        if ($boundariesOpt == 3) {
            $mapX[0] += 0.5;
            $mapX[1] -= 0.5;
        } else if ($boundariesOpt == 4) {
            $mapY[0] -= 5.5;
            $mapY[1] += 5.5;
        }

        /* $mapX[0] *= $siz;
        $mapX[1] *= $siz;
        $mapY[0] *= $siz;
        $mapY[1] *= $siz; */

        ['x'=>$x, 'y'=>$y] = $oct->getCenter()->coordinates();
        
        return ($x > $mapX[0] && $x < $mapX[1] && $y > $mapY[0] && $y < $mapY[1]);
    }

    // big messy method checks if subj object (can be either octagon or vector) collides with any other element on the map (cars, curves or pitwall)
    function detectCollision($subj, $isVector=false, $ignoreElements = array()) {
        
        /* self::dump('/// ANALIZING COLLISION OF '.(($isVector)? 'VECTOR':'CAR POSITION'),$subj->getCenter()->coordinates());
        self::dump('/// DUMP SUBJECT',$subj); */

        foreach (self::getObjectListFromDb("SELECT * FROM game_element") as $element) {

            if (!is_null($element['pos_x']) && !is_null($element['pos_y']) &&
                !in_array($element['id'],$ignoreElements) &&
                $element['entity'] != 'gearVector' && $element['entity'] != 'boostVector') {

                $pos = new VektoracePoint2($element['pos_x'],$element['pos_y']);

                /* self::dump('// WITH '.$element['entity'].' '.$element['id'].' AT ', $pos->coordinates()); */

                $obj;
                switch ($element['entity']) {
                    case 'car': $obj = new VektoraceOctagon2($pos, $element['orientation']);
                        break;
                    case 'curve': $obj = new VektoraceCurve($pos, $element['orientation']);
                        break;
                    case 'curb': $obj = new VektoraceCurb($element['id']);
                        break;
                    case 'pitwall': $obj = new VektoracePitwall($pos, $element['orientation']);
                        break;
                    default:
                        throw new Exception('Cannot detect collision with invalid or unidentified object');
                }

                /* self::dump('/// DUMP SUBJECT',$subj);
                self::dump('/// DUMP SUBJECT VERTICES',$subj->getVertices());
                self::dump('/// DUMP OBJECT',$obj);
                self::dump('/// DUMP OBJECT VERTICES',$obj->getVertices()); */
                if ($subj->collidesWith($obj)) return true;
            }
        }

        $in = false;
        if ($isVector) {
            $in = self::withinMapBoundaries($subj->getTopOct()) && self::withinMapBoundaries($subj->getBottomOct());
        } else $in = self::withinMapBoundaries($subj);

        if ($in) return false;
        else {
            self::trace('// -!- OUT OF BOUNDS -!-');
            return true;
        }
    }

    #endregion

    //++++++++++++++++//
    // PLAYER ACTIONS //
    //++++++++++++++++//
    #region player actions

    // [functions responding to ajaxcall formatted and forwarded by action.php script. function names should always match action name]

    // selectPosition: specific function that selects and update db on new position for currently active player car.
    //                 should be repurposed to match all cases of selecting positions and cars moving
    function placeFirstCar($x,$y) {

        if ($this->checkAction('placeFirstCar')) {

            // check if sent pos is valid (and player didn't cheat) by doing dot product of positioning window norm and pos vector to window center (result should be close to 0 as vectors should be orthogonal)
            $args = self::argFirstPlayerPositioning();
            
            $dir = -$args['rotation']+4;

            $center = new VektoracePoint2($args['center']['x'],$args['center']['y']);
            $norm = VektoracePoint2::createPolarVector(1,($dir)*M_PI_4);

            $pos = VektoracePoint2::displacementVector($center, new VektoracePoint2($x,$y));
            $pos->normalize();

            if (abs(VektoracePoint2::dot($norm, $pos)) > 0.1) throw new BgaUserException('Invalid car position');

            $id = self::getActivePlayerId();

            $sql = "UPDATE game_element
                SET pos_x = $x, pos_y = $y
                WHERE id = $id";
        
            self::DbQuery($sql);

            self::notifyAllPlayers('placeFirstCar', clienttranslate('${player_name} chose their car starting position'), array(
                'player_id' => $id,
                'player_name' => self::getActivePlayerName(),
                'x' => $x,
                'y' => $y
                ) 
            );

            $this->gamestate->nextState();
        }
    }

    function placeCarFS($refCarId,$posIdx) {

        if ($this->checkAction('placeCarFS')) {

            $args = self::argFlyingStartPositioning();

            $pos = $args['positions'];

            //[$refCarId]['positions'][$posIdx]

            foreach ($args['positions'] as $refcar) {

                if ($refcar['carId'] == $refCarId) {

                    if (array_key_exists($posIdx, $refcar['positions'])) {

                        $pos = $refcar['positions'][$posIdx];

                        if (!$pos['valid']) throw new BgaUserException('Illegal car position');

                        ['x'=>$x,'y'=>$y] = $pos['coordinates'];

                        $id = self::getActivePlayerId();
            
                        $sql = "UPDATE game_element
                                SET pos_x = $x, pos_y = $y
                                WHERE id = $id";
                    
                        self::DbQuery($sql);
            
                        self::notifyAllPlayers('placeCarFS', clienttranslate('${player_name} chose their car starting position'), array(
                            'player_id' => $id,
                            'player_name' => self::getActivePlayerName(),
                            'x' => $x,
                            'y' => $y,
                            'refCar' => $refCarId
                        ));
            
                        $this->gamestate->nextState();
                        return;

                    } else throw new BgaUserException('Invalid car position');
                }
            }
            throw new BgaUserException('Invalid reference car id');            
        }
    }

    function chooseTokensAmount($tire,$nitro, $pitStop = false) {
        if ($this->checkAction('chooseTokensAmount')) {

            $args = $this->gamestate->state()['args'];

            if ($tire < $args['tire'] || $nitro < $args['nitro']) throw new BgaUserException('You cannot have a negative transaction of tokens');
            if ($tire > 8 || $nitro > 8) throw new BgaUserException('You cannot have more than 8 tokens for each type');
            if ($tire+$nitro != min($args['tire'] + $args['nitro'] + $args['amount'], 16)) throw new BgaUserException('You have to fill your token reserve with the correct amount');

            $id = self::getActivePlayerId();

            $prevTokens = self::getPlayerTokens($id);

            $sql = "UPDATE player
                    SET player_tire_tokens = $tire, player_nitro_tokens = $nitro
                    WHERE player_id = $id";

            self::DbQuery($sql);

            $action = clienttranslate("chose to start the game with ");

            if ($pitStop) {
                $action = clienttranslate("refilled their token reserve with ");

                $tire = $tire - $prevTokens['tire'];
                $nitro = $nitro - $prevTokens['nitro'];
            }

            self::notifyAllPlayers('chooseTokensAmount', clienttranslate('${player_name} ${action} ${tire} TireTokens and ${nitro} NitroTokens'), array(
                'player_id' => $id,
                'player_name' => self::getActivePlayerName(),
                'action' => $action,
                'tire' => $tire,
                'nitro' => $nitro,
                'prevTokens' => $prevTokens
                )
            );

            $this->gamestate->nextState();
        }
    }

    // chooseStartingGear: server function responding to user input when a player chooses the gear vector for all players (green-light phase)
    function chooseStartingGear($n) {
        if ($this->checkAction('chooseStartingGear')) {

            if ($n<3 && $n>0) throw new BgaUserException('You may only choose between the 3rd to the 5th gear for the start of the game');
            if ($n<1 || $n>5) throw new BgaUserException('Invalid gear number');

            $sql = "UPDATE player
                    SET player_current_gear = $n";
        
            self::DbQuery($sql);

            self::incGameStateValue('turn_number', 1);
            foreach (self::getCollectionFromDb("SELECT player_id FROM player") as $id => $player) {
                self::setStat($n,'average_gear',$id);
            }

            self::notifyAllPlayers('chooseStartingGear', clienttranslate('${player_name} chose the ${n}th gear as the starting gear for every player'), array(
                'player_name' => self::getActivePlayerName(),
                'n' => $n,
                ) 
            );
        }

        $this->gamestate->nextState();
    }

    // declareGear: same as before, but applies only to active player, about his gear of choise for his next turn. thus DB is updated only for the player's line
    function declareGear($n) {
        if ($this->checkAction('declareGear')) {

            if ($n<1 || $n>5) throw new BgaUserException('Invalid gear number');

            $id = self::getActivePlayerId();

            $args = self::argFutureGearDeclaration()['gears'];
            $gearProp = $args[$n-1];

            $curr = self::getPlayerCurrentGear($id);

            if ($gearProp == 'unavail') throw new BgaUserException('You are not allowed to choose this gear right now');
            if ($gearProp == 'denied') {
                if ($n > $curr) throw new BgaUserException('You cannot shift upwards after an Emergency Break');
                if ($n < $curr) throw new BgaUserException('You cannot shift downwards after suffering a push from an enemy car');
            }

            if ($gearProp == 'tireCost' || $gearProp == 'nitroCost')  {

                $type = str_replace('Cost','',$gearProp);

                $tokens = self::getPlayerTokens($id)[$type];

                $cost = abs($curr - $n)-1;
                $tokenExpense = $tokens - $cost;

                if ($tokenExpense < 0) throw new BgaUserException('You don\'t have enough '.$type.' tokens to do this action');

                self::incStat($cost,$type.'_used',$id);

                $meanGear = self::getStat('average_gear',$id);
                $turns = self::getStat('turns_number',$id);

                $meanGear = ($meanGear*$turns + $n)/($turns+1);
                self::setStat($meanGear,'average_gear',$id);

                $sql = "UPDATE player
                        SET player_".$type."_tokens = $tokenExpense
                        WHERE player_id = $id";

                self::DbQuery($sql);

                self::notifyAllPlayers('gearShift', clienttranslate('${player_name} performed ${shiftType} of step ${step} by spending ${cost} ${tokenType} tokens'), array(
                    'player_name' => self::getActivePlayerName(),
                    'player_id' => self::getActivePlayerId(),
                    'shiftType' => (($type == 'tire')? 'a downshift' : 'an upshift'),
                    'step' => $cost + 1,
                    'cost' => $cost,
                    'tokenType' => $type,
                    'tokensAmt' => $tokenExpense
                ));
            }

            $sql = "UPDATE player
                    SET player_current_gear = $n
                    WHERE player_id = $id";
        
            self::DbQuery($sql);

            self::notifyAllPlayers('declareGear', clienttranslate('${player_name} will use the ${n}th gear on their next turn'), array(
                'player_name' => self::getActivePlayerName(),
                'player_id' => self::getActivePlayerId(),
                'n' => $n,
                ) 
            );

            $this->gamestate->nextState('nextPlayer');
        }
    }

    function placeGearVector($position) {

        if ($this->checkAction('placeGearVector')) {

            foreach (self::argGearVectorPlacement()['positions'] as $pos) {

                if ($pos['position'] == $position) {

                    if (!$pos['legal']) throw new BgaUserException('Illegal gear vector position');
                    if ($pos['denied']) throw new BgaUserException('Gear vector position denied by the previous shunking you suffered');
                    if ($pos['offTrack']) throw new BgaUserException('You cannot pass a curve from behind');
                    if (!$pos['carPosAvail']) throw new BgaUserException("This gear vector doesn't allow any vaild car position");

                    $id = self::getActivePlayerID();

                    $tireTokens = self::getPlayerTokens($id)['tire'];

                    $optString = '';

                    // CHECK TIRE COST
                    if ($pos['tireCost']) {

                        if ($tireTokens == 0) throw new BgaUserException(self::_("You don't have enough Tire Tokens to do this move"));
                        
                        $sql = "UPDATE player
                                SET player_tire_tokens = player_tire_tokens -1
                                WHERE player_id = $id";
                        self::DbQuery($sql);

                        // APPLY PENALITY (NO DRAFTING ATTACK MOVES ALLOWED)
                        self::DbQuery("UPDATE penalities_and_modifiers SET NoDrafting = 1 WHERE player = $id");

                        $tireTokens -= 1;
                        self::incStat(1,'tire_used',$id);
                        $optString = ' performing a "side shift" (-1 TireToken)'; // in italian: 'scarto laterale'
                    }/*  else $tireTokens = null; */

                    $orientation = self::getUniqueValueFromDb("SELECT orientation FROM game_element WHERE id=$id");
                    $gear = self::getPlayerCurrentGear($id);
                    ['x'=>$x, 'y'=>$y] = $pos['vectorCoordinates'];

                    // INSERT VECTOR ON TABLE
                    $sql = "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                            VALUES ('gearVector', $gear, $x, $y, $orientation)";
                    self::DbQuery($sql);

                    // UPDTE CURVE PROGRESS BASED ON VECTOR TOP
                    $curveProgress = $pos['curveProgress'];
                    self::DbQuery("UPDATE player SET player_curve_zone = $curveProgress WHERE player_id = $id");

                    self::notifyAllPlayers('placeGearVector', clienttranslate('${player_name} placed the gear vector'.$optString), array(
                        'player_name' => self::getActivePlayerName(),
                        'player_id' => $id,
                        'x' => $x,
                        'y' => $y,
                        'direction' => $orientation,
                        'tireTokens' => $tireTokens,
                        'gear' => $gear
                    ));

                    if (self::getPlayerTokens($id)['nitro'] == 0 ||
                        self::getUniqueValueFromDb("SELECT BoxBox FROM penalities_and_modifiers WHERE player = $id") ||
                        $gear == 1 ||
                        !self::argBoostVectorPlacement()['hasValid']) {
                        $this->gamestate->nextState('skipBoost');
                        return;
                    }

                    $this->gamestate->nextState('boostPromt');
                    return;
                }
            }

            throw new BgaUserException('Invalid gear vector position');
        }
    }

    function brakeCar() {
        if ($this->checkAction('brakeCar')) {

            // check if player has indeed no valid positionts, regardless of which state he takes this action from (car or vector placement)
            $arg = self::argGearVectorPlacement();
            if ($arg['hasValid']) throw new BgaUserException('You cannot perform this move if you already have valid positions');

            /* if ($this->gamestate->state()['name'] == 'carPlacement') {
                // if called during this state, a vector has already been places so it has to be removed from db
                $sql = "DELETE FROM game_element
                        WHERE entity = 'gearVector'";
                self::DbQuery($sql);
            } */
            
            // APPLY PENALITY (NO BLACK MOVES, NO ATTACK MANEUVERS, NO SHIFT UP)
            self::DbQuery("UPDATE penalities_and_modifiers SET NoBlackMov = 1, NoAttackMov = 1, NoShiftUp = 1 WHERE player = ".self::getActivePlayerId());

            self::notifyAllPlayers('brakeCar', clienttranslate('${player_name} had to brake to avoid a collision or invalid move'), array(
                'player_name' => self::getActivePlayerName()
            ));

            $this->gamestate->nextState('slowdownOrBrake');
            return;
        }
    }

    function giveWay() {
        if ($this->checkAction('giveWay')) {

            $arg = self::argGearVectorPlacement();
            $id = self::getActivePlayerId();

            if (!$arg['canGiveWay']) throw new BgaUserException('You cannot give way if no other player is obstructing your path');

            // APPLY PENALITY (NO ATTACK MANEUVERS)
            self::DbQuery("UPDATE penalities_and_modifiers SET NoAttackMov = 1 WHERE player = $id");

            $playerTurnPos = self::getPlayerTurnPos($id);
            $enemyTurnPos = $playerTurnPos + 1;
            $enemy = self::getPlayerTurnPosNumber($enemyTurnPos);

            self::notifyAllPlayers('giveWay', clienttranslate('${player_name} gave way to ${player_name2} to avoid a collision'), array(
                'player_name' => self::getActivePlayerName(),
                'player_id' => $id,
                'player_name2' => self::getPlayerNameById($enemy),
                'player2_id' => $enemy
            ));

            /* $playerTurnPos = self::getPlayerTurnPos($id);
            $enemyTurnPos = $playerTurnPos + 1;
            $enemy = self::getPlayerTurnPosNumber($enemyTurnPos);
            self::DbQuery("UPDATE player SET turn_pos = $enemyTurnPos WHERE player_id = $id");
            self::DbQuery("UPDATE player SET turn_pos = $playerTurnPos WHERE player_id = $enemy"); */

            $this->gamestate->nextState('setNewTurnOrder');
            return;
        }
    }

    function rotateAfterBrake($dir) {
        if ($this->checkAction('rotateAfterBrake')) {

            $id = self::getActivePlayerId();
            $arg = self::argEmergencyBrake()['directionArrows'];

            if (array_key_exists($dir,$arg)) {

                $direction = $arg[$dir];

                $orientation = self::getUniqueValueFromDb("SELECT orientation FROM game_element WHERE id = $id");
                $orientation = ($orientation + $direction['rotation'] + 8) % 8;

                $sql = "UPDATE game_element
                        SET orientation = $orientation
                        WHERE id = $id";
                self::DbQuery($sql);

                self::notifyAllPlayers('rotateAfterBrake', clienttranslate('${player_name} had to stop their car'), array(
                    'player_name' => self::getActivePlayerName(),
                    'player_id' => $id,
                    'rotation' => $dir-1
                ));

                $this->gamestate->nextState('');
                return;

            } else throw new BgaUserException("Invalid direction");
        }
    }

    function useBoost($use) {
        if ($this->checkAction('useBoost')) {

            if($use) {

                $id = self::getActivePlayerId();
                $nitroTokens = self::getPlayerTokens($id)['nitro'];

                if ($nitroTokens == 0) throw new BgaUserException(self::_("You don't have enough Nitro Tokens to use a boost"));

                $sql = "UPDATE player
                        SET player_nitro_tokens = player_nitro_tokens -1
                        WHERE player_id = $id AND player_nitro_tokens > 0";
                self::DbQuery($sql);

                $nitroTokens += -1;
                self::incStat(1,'nitro_used',$id);
                self::incStat(1,'boost_number',$id);
                self::notifyAllPlayers('useBoost', clienttranslate('${player_name} chose to use a boost vector to extend their car movement (-1 NitroToken)'), array(
                    'player_name' => self::getActivePlayerName(),
                    'player_id' => $id,
                    'nitroTokens' => $nitroTokens
                ));
            }

            $this->gamestate->nextState(($use)? 'use' : 'skip');
        }
    }

    function placeBoostVector($n) {

        if ($this->checkAction('placeBoostVector')) {

            ['positions'=>$boostAllPos, 'direction'=>$direction] = self::argBoostVectorPlacement();

            foreach ($boostAllPos as $pos) {

                if ($pos['length'] == $n) {

                    if (!$pos['legal']) throw new BgaUserException('Illegal boost vector lenght');
                    if (!$pos['carPosAvail']) throw new BgaUserException('No legal car position available with this boost lenght');

                    ['x'=>$x, 'y'=>$y] = $pos['vecCenterCoordinates'];
                    
                    $sql = "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                            VALUES ('boostVector', $n, $x, $y, $direction)";

                    self::DbQuery($sql);

                    self::notifyAllPlayers('chooseBoost', clienttranslate('${player_name} placed the ${n}th boost vector'), array(
                        'player_name' => self::getActivePlayerName(),
                        'player_id' => self::getActivePlayerID(),
                        'n' => $n,
                        'vecX' => $x,
                        'vecY' => $y,
                        'direction' => $direction
                    ));

                    $this->gamestate->nextState();
                    return;
                }
            }

            throw new BgaVisibleSystemException('Invalid boost length');
        }
    }

    function placeCar($position, $direction) {

        if ($this->checkAction('placeCar')) {

            $allPos = self::argCarPlacement()['positions'];

            // I SHOULD FILTER HERE INSTEAD OF POINTLESS FOREACH FOR USING ONE ITEM ONLY
            foreach ($allPos as $pos) {
                
                if ($pos['position'] == $position) {

                    if (!$pos['legal']) throw new BgaUserException('Illegal car position');
                    if ($pos['denied']) throw new BgaUserException('Car position denied by the previous shunking you suffered');

                    $allDir = $pos['directions'];

                    foreach ($allDir as $dir) {
                        
                        if ($dir['direction'] == $direction) {

                            $id = self::getActivePlayerId();
                            
                            $previousPos = self::getPlayerCarOctagon($id);
                            
                            $tireTokens = self::getPlayerTokens($id)['tire'];

                            $optString = '';

                            if ($dir['black']) {

                                if (self::getUniqueValueFromDb("SELECT NoBlackMov FROM penalities_and_modifiers WHERE player = $id"))
                                    throw new BgaUserException(self::_('You cannot select "black moves" after an Emergency Break'));

                                if ($tireTokens == 0)
                                    throw new BgaUserException(self::_("You don't have enough Tire Tokens to do this move"));
                                
                                $sql = "UPDATE player
                                        SET player_tire_tokens = player_tire_tokens -1
                                        WHERE player_id = $id";
                                self::DbQuery($sql);

                                // APPLY PENALITY (NO DRAFTING ATTACK MOVES ALLOWED)
                                self::DbQuery("UPDATE penalities_and_modifiers SET NoDrafting = 1 WHERE player = $id");

                                $tireTokens--;
                                self::incStat(1,'tire_used',$id);
                                $optString = ' performing a "black" move (-1 TireToken)';
                            }

                            ['x'=>$x, 'y'=>$y] = $pos['coordinates'];
                            $rotation = $dir['rotation'];
                            $orientation = self::getUniqueValueFromDb("SELECT orientation FROM game_element WHERE id = $id");
                            $orientation = ($orientation + $rotation + 8) % 8;

                            $sql = "UPDATE game_element
                                    SET pos_x = $x, pos_y = $y, orientation = $orientation
                                    WHERE id = $id";
                            self::DbQuery($sql);

                            // remove any vectror used during movement
                            $sql = "DELETE FROM game_element
                                    WHERE entity = 'gearVector' OR entity = 'boostVector'";
                            self::DbQuery($sql);

                            // UPDATE CURVE PROGRESS
                            $curveProgress = $pos['curveProgress'];
                            self::DbQuery("UPDATE player SET player_curve_zone = $curveProgress WHERE player_id = $id");

                            self::notifyAllPlayers('placeCar', clienttranslate('${player_name} placed their car'.$optString), array(
                                'player_name' => self::getActivePlayerName(),
                                'player_id' => $id,
                                'x' => $x,
                                'y' => $y,
                                'rotation' => $rotation,
                                'tireTokens' => $tireTokens
                            ));

                            $currPos = self::getPlayerCarOctagon($id);
                            $pw = self::getPitwall();

                            if (self::isPlayerAfterLastCurve($id)) {
                                if ($previousPos->inPitZone($pw, 'EoC') && $pos['byFinishLine'] && self::getUniqueValueFromDb("SELECT BoxBox FROM penalities_and_modifiers WHERE player = $id")) throw new BgaUserException('You cannot pass by the finish line after calling "BoxBox!"');
                                if ($previousPos->inPitZone($pw, 'entrance')) {
                                    if ($pos['byBox'] && !is_null(self::getUniqueValueFromDb("SELECT id FROM game_element WHERE entity = 'boostVector'"))) throw new BgaUserException('You cannot enter the box using a boost vector');
                                    if ($pos['byBox'] && self::getPlayerCurve($id)['number'] == 1) throw new BgaUserException('You cannot go to the Pit-Box at the start of the race');
                                    if ($pos['byBox'] && self::getPlayerLap($id) == self::getGameStateValue('number_of_laps')-1) throw new BgaUserException('You cannot go to the Pit-Box on your last lap');
                                }
                                if ($currPos->inPitZone($pw, 'box', 'any') && $currPos->getDirection() != $pw->getDirection()) throw new BgaUserException(self::_("You are not allowed to rotate the car while inside the Pit-Box"));

                                // -- CAR OVERSHOOTS BOX ENTRANCE (was in entrance, now is in exit)
                                if ($previousPos->inPitZone($pw,'entrance') && $currPos->inPitZone($pw,'exit','any')) {
                                    // overshot pitbox entrance -> penality

                                    $newPosPoint = $currPos->boxOvershootPenality($pw);
                                    $newPosPoint = new VektoraceOctagon2($newPosPoint,$currPos->getDirection());

                                    if (self::detectCollision($newPosPoint)) {
                                        $newPosPoint = $currPos->boxOvershootPenality($pw, true);
                                        $newPosPoint = new VektoraceOctagon2($newPosPoint,$currPos->getDirection());                              
                                    }

                                    ['x'=>$x,'y'=>$y] = $newPosPoint->getCenter()->coordinates();
                                    $orientation = $pw->getDirection();

                                    $sql = "UPDATE game_element
                                            SET pos_x = $x, pos_y = $y, orientation = $orientation
                                            WHERE id = $id";
                                    self::DbQuery($sql);

                                    $rotation = $orientation - $currPos->getDirection();

                                    self::notifyAllPlayers('boxEntranceOvershoot', clienttranslate('${player_name} wasn\'t able to stop by the box. They won\'t be refilling their tokens next turn'), array(
                                        'player_name' => self::getActivePlayerName(),
                                        'player_id' => $id,
                                        'x' => $x,
                                        'y' => $y,
                                        'rotation' => $rotation,
                                    ));

                                    $this->gamestate->nextState('endMovement');
                                    return;
                                }

                                // -- ELSE CHECK IF CAR IS INSIDE BOX
                                if ($currPos->inPitZone($pw, 'box', 'any')) {
                                    // if nose is in box (and previous was not. should not be possible but avoids double refill)
                                    if ($currPos->inPitZone($pw,'box') && !$previousPos->inPitZone($pw,'box')) {

                                        self::notifyAllPlayers('boxEntrance', clienttranslate('${player_name} entered the pit box'), array(
                                            'player_name' => self::getActivePlayerName(),
                                            'player_id' => $id,
                                        ));

                                        self::incStat(1,'pitstop_number',$id);
    
                                        $this->gamestate->nextState('boxEntrance'); // refill tokens
                                    } else $this->gamestate->nextState('endMovement'); // else car should be in exit, skips attack

                                    return;
                                }
                            }

                            $this->gamestate->nextState('attack');
                            return;
                        }
                    }

                    throw new BgaVisibleSystemException('Invalid car direction');

                }
            }

            throw new BgaUserException('Invalid car position');
        }
    }

    function engageManeuver($enemy, $action, $posIndex) {
        if ($this->checkAction('engageManeuver')) {

            $args = self::argAttackManeuvers();
            $id = self::getActivePlayerId();

            $penalities = self::getObjectFromDb("SELECT NoDrafting, NoAttackMov, BoxBox FROM penalities_and_modifiers WHERE player = $id");
            if ($penalities['NoAttackMov'] || $penalities['BoxBox']) throw new BgaUserException('You are currently restricted from performing any action maneuver');
            if (($action == 'drafting' || $action == 'push' || $action == 'slingshot') && $penalities['NoDrafting']) throw new BgaUserException('You cannot perform drafting maneuvers after spending tire tokens during your movement phase');

            $mov = null;
            foreach ($args['attEnemies'] as $en) {
                if ($en['id'] == $enemy) {
                    $mov = $en['maneuvers'][$action];
                }
            }
            if (is_null($mov)) throw new BgaUserException('Invalid attack move');


            if (!$mov['active']) throw new BgaUserException('You do not pass the requirements to be able to perform this maneuver');
            if (!$mov['legal']) throw new BgaUserException('Illegal attack position');

            $attPos = $mov['attPos'];
            if ($action == 'slingshot') {
                if (!$attPos[$posIndex]['valid']) throw new BgaUserException('Illegal attack position');
                $attPos = $attPos[$posIndex]['pos'];
            }

            ['x'=>$x, 'y'=>$y] = $attPos;

            $posOct = new VektoraceOctagon2(new VektoracePoint2($x,$y),self::getPlayerCarOctagon($id)->getDirection());
            if ($posOct->inPitZone(self::getPitwall(),'box')) throw new BgaUserException('You cannot enter the box with an attack maneuver');

            self::DbQuery("UPDATE game_element SET pos_x = $x, pos_y = $y WHERE id = $id"); // don't worry about db update being before checking nitroTokens, any thrown exception discards the transaction and reset db top previous state

            $nitroTokens = null; // needed for slingshot

            switch ($action) {
                case 'drafting':
                    $desc = clienttranslate('${player_name} took the slipstream of ${player_name2}');                
                    break;

                case 'push':
                    $desc = clienttranslate('${player_name} pushed ${player_name2} form behind');
                    self::DbQuery("UPDATE penalities_and_modifiers SET NoShiftDown = 1 WHERE player = $enemy");
                    break;

                case 'slingshot':

                    $nitroTokens = self::getPlayerTokens($id)['nitro'] - 1;
                    if ($nitroTokens < 0) throw new BgaUserException("You don't have enough Nitro Tokens to perform this action");
                    self::DbQuery("UPDATE player SET player_nitro_tokens = $nitroTokens WHERE player_id = $id");
                    self::incStat(1,'nitro_used',$id);
                    
                    $desc = clienttranslate('${player_name} overtook ${player_name2} with a Slingshot maneuver (-1 Nitro Token)');
                    break;

                case 'leftShunk':
                    $desc = clienttranslate('${player_name} shunked ${player_name2} from the left');
                    self::DbQuery("UPDATE penalities_and_modifiers SET DeniedSideLeft = 1 WHERE player = $enemy");
                    break;

                case 'rightShunk':
                    $desc = clienttranslate('${player_name} shunked ${player_name2} from the right');
                    self::DbQuery("UPDATE penalities_and_modifiers SET DeniedSideRight = 1 WHERE player = $enemy");
                    break;
            }

            self::incStat(1,'attMov_performed',$id);
            self::incStat(1,'attMov_suffered',$enemy); // counting simple drafting too

            self::notifyAllPlayers('engageManeuver',$desc,array(
                'player_name' => self::getActivePlayerName(),
                'player_id' => $id,
                'player_name2' => self::getPlayerNameById($enemy),
                'enemy' => $enemy,
                'attackPos' => $attPos,
                'nitroTokens' => $nitroTokens,
                'action' => $action
            ));

            $this->gamestate->nextState('completeManeuver');
        }
    }

    function skipAttack() {
        if ($this->checkAction('skipAttack')) {
            self::notifyAllPlayers('skipAttack',clienttranslate('${player_name} did not perform any attack maneuver'),array(
                'player_name' => self::getActivePlayerName(),
                'player_id' => self::getActivePlayerId()
            ));

            $this->gamestate->nextState('noManeuver');
        }
    }

    function boxBox($skip) {
        if ($this->checkAction('boxBox')) {
            $id = self::getActivePlayerId();

            if ($skip) {
                self::DbQuery("UPDATE penalities_and_modifiers SET BoxBox = 0 WHERE player = $id");

                $this->gamestate->nextState('');
            }
            else {
                self::DbQuery("UPDATE penalities_and_modifiers SET BoxBox = 1 WHERE player = $id");

                self::notifyAllPlayers('boxBox',clienttranslate('${player_name} called "BoxBox!"'),array(
                    'player_name' => self::getActivePlayerName(),
                    'player_id' => self::getActivePlayerId()
                ));

                $this->gamestate->nextState('');
            }
        }
    }

    #endregion
    
    //++++++++++++++++++++++//
    // STATE ARGS FUNCTIONS //
    //++++++++++++++++++++++//
    #region state args

    // [functions that extract data (somme kind of associative array) for client to read during a certain game state. name should match the one specified on states.inc.php]

    // returns coordinates and useful data to position starting placement area. rotation independent
    function argFirstPlayerPositioning() {
        
        $pw = self::getPitwall();
        $anchorVertex = $pw->getVertices()[2][1];

        $placementWindowSize = array('width' => VektoraceGameElement::getOctagonMeasures()['size'], 'height' => VektoraceGameElement::getOctagonMeasures()['size']*5);
        
        $ro = $placementWindowSize['width']/2;
        $the = ($pw->getDirection()-4) * M_PI_4;
        $windowCenter = $anchorVertex->translate($ro*cos($the), $ro*sin($the));

        $ro = $placementWindowSize['height']/2;
        $the = ($pw->getDirection()-2) * M_PI_4;
        $windowCenter = $windowCenter->translate($ro*cos($the), $ro*sin($the));

        return array("anchorPos" => $anchorVertex->coordinates(), "rotation" => 4 - $pw->getDirection(), 'center' => $windowCenter->coordinates());
    }

    // returns coordinates and useful data for all available (valid and not) flying start positition for each possible reference car
    function argFlyingStartPositioning() {

        $activePlayerTurnPosition = self::getPlayerTurnPos(self::getActivePlayerId());
        $playerBefore = self::getPlayerTurnPosNumber($activePlayerTurnPosition-1); 
        $allpos = array();

        foreach (self::loadPlayersBasicInfos() as $id => $playerInfos) { // for each reference car on the board

            if ($playerInfos['player_no'] < $activePlayerTurnPosition) {

                $hasValid = false;

                $playerCar = self::getPlayerCarOctagon($id);
                $fsOctagons = $playerCar->getAdjacentOctagons(3,true);

                $right_3 = new VektoraceOctagon2($fsOctagons[2], ($playerCar->getDirection()+1 +8)%8);
                $center_3 = new VektoraceOctagon2($fsOctagons[1], $playerCar->getDirection());
                $left_3 = new VektoraceOctagon2($fsOctagons[0], ($playerCar->getDirection()-1 +8)%8);

                $fsPositions = array(...$right_3->getAdjacentOctagons(3,true), ...$center_3->getAdjacentOctagons(3,true), ...$left_3->getAdjacentOctagons(3,true));
                $fsPositions = array_unique($fsPositions, SORT_REGULAR);

                $positions = array();
                foreach ($fsPositions as $pos) { // for each position of the reference car

                    $playerBeforeCar = self::getPlayerCarOctagon($playerBefore); // construct octagon from ahead player's position
                    $posOct = new VektoraceOctagon2($pos, $playerBeforeCar->getDirection()); // construct octagon of current position

                    /* $vertices = $posOct->getVertices();
                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v); */

                    $valid = $posOct->isBehind($playerBeforeCar,false) && !self::detectCollision($posOct);
                    if ($valid) $hasValid = true;

                    // if pos is not behind or a collision is detected, report it as invalid
                    $positions[] = array(
                        'coordinates' => $pos->coordinates(),
                        /* 'vertices' => $vertices, */
                        // 'debug' => $posOct->isBehind($playerBeforeCar),
                        'valid' => $valid
                    );
                }

                foreach ($fsOctagons as &$oct) {
                    $oct = $oct->coordinates();
                } unset($oct);

                $allpos[] = array(
                    'carId' => $id,
                    'coordinates' => $playerCar->getCenter()->coordinates(),
                    'FS_octagons' => $fsOctagons,
                    'positions' => $positions,
                    'hasValid' => $hasValid
                );
            }
        }

        return array ('positions' => $allpos);
    }

    // returns current token amount for active player
    function argTokenAmountChoice() {

        $sql = "SELECT player_tire_tokens tire, player_nitro_tokens nitro
                FROM player
                WHERE player_id = ".self::getActivePlayerId();

        $tokens = self::getObjectFromDB($sql);

        return array('tire' => $tokens['tire'], 'nitro' => $tokens['nitro'], 'amount'=> 8);
    }

    function argGreenLight() {
        return array('gears' => array('unavail','unavail','avail','avail','avail'));
    }

    // returns coordinates and useful data to position vector adjacent to the player car
    function argGearVectorPlacement($predictFromGear=null) {

        $id = self::getActivePlayerId();

        $playerCar = self::getPlayerCarOctagon($id);

        $currentGear = self::getPlayerCurrentGear($id);
        if (!is_null($predictFromGear)) $currentGear = $predictFromGear;

        $direction = $playerCar->getDirection();
        
        $positions = array();
        $posNames = array('right-side','right','front','left','left-side');

        $deniedSide = self::getObjectFromDb("SELECT DeniedSideLeft L, DeniedSideRight R FROM penalities_and_modifiers WHERE player = $id");

        ['number'=>$curveNum, 'zone'=>$curveZone] = self::getPlayerCurve($id);
        $playerCurve = new VektoraceCurb($curveNum);

        $playerTurnPos = self::getPlayerTurnPos($id);
        $ignorePlayer = ($playerTurnPos == self::getPlayersNumber() || self::getPlayerTurnPos($id) == 1)? [] : [self::getPlayerTurnPosNumber($playerTurnPos+1)];

        // iter through all 5 adjacent octagon
        foreach ($playerCar->getAdjacentOctagons(5) as $i => $anchorPos) {

            if (!($currentGear==1 && ($i==0 || $i==4))) {

                // construct vector from that anchor position
                $vector = new VektoraceVector2($anchorPos, $direction, $currentGear, 'bottom');
                
                // calc difference between current curve zone and hypotetical vector top curve zone
                $curveZoneStep = $vector->getTopOct()->getCurveZone($playerCurve) - $curveZone; 

                // return vector center to make client easly display it, along with anchor pos for selection octagon, and special properties flag
                $positions[] = array(
                    'position' => $posNames[$i],
                    'anchorCoordinates' => $anchorPos->coordinates(),
                    'vectorCoordinates' => $vector->getCenter()->coordinates(),
                    'tireCost' => ($i == 0 || $i == 4), // pos 0 and 4 are right-side and left-side respectevly, as AdjacentOctagons() returns position in counter clockwise fashion
                    'legal' => !self::detectCollision($vector,true),
                    'denied' => ($i < 2 && $deniedSide['R']) || ($i > 2 && $deniedSide['L']),
                    'obstructed' => !self::detectCollision($vector,true, $ignorePlayer),
                    'offTrack' =>  $curveZoneStep > 3 || $curveZoneStep < -1 || ($curveZoneStep < 0 && $vector->getTopOct()->getCurveZone($playerCurve) == 0), // if curve zone step is too high or backwards, assuming player is going off track
                    'curveProgress'=> $vector->getTopOct()->getCurveZone($playerCurve),
                    'carPosAvail' => self::argCarPlacement($vector)['hasValid']
                );
            }
        }

        $hasValid = false;
        foreach ($positions as $pos) {
            if ($pos['carPosAvail'] && $pos['legal'] && !$pos['denied'] && !$pos['offTrack'] && !($pos['tireCost'] && self::getPlayerTokens(self::getActivePlayerId())['tire']<1)) {

                $hasValid = true;
                break;
            }
        }

        // DETECT GIVE WAY POSSIBILITY
        // retrieve player with turn position after
        // if i remove him from the elements, does detectCollission now return false for any of the available positions?
        // if yes, then player before is obstructing, grant possibility to giveWay
        $canGiveWay = false;
        if (!$hasValid) {
            foreach ($positions as $pos) {

                // if valid is found no need to check for canGiveWay
                if (!$pos['denied'] && !$pos['legal'] && $pos['obstructed']) {
                    $canGiveWay = true;
                    break; 
                }
            }
        }

        return array('positions' => $positions, 'direction' => $direction, 'gear' => $currentGear, 'hasValid' => $hasValid, 'canGiveWay' => $canGiveWay);
    }

    function argEmergencyBrake() {

        $carOct = self::getPlayerCarOctagon(self::getActivePlayerId());

        $dirNames = array('right', 'straight', 'left');

        $directions = array();
        foreach ($carOct->getAdjacentOctagons(3) as $i => &$dir) {
            $directions[] = array(
                'direction' => $dirNames[$i],
                'coordinates' => $dir->coordinates(),
                'rotation' => $i-1,
                'black' => false
            );
        } unset($dir);

        return array('directionArrows' => $directions, 'direction' => $carOct->getDirection());
    }

    // works similarly to method above, but returns adjacent octagons in a chain to get a number of octagon straight in front of each others
    function argBoostVectorPlacement() {

        $gearVec = self::getPlacedVector('gear');
        $gear = $gearVec->getLength();

        $next = $gearVec->getTopOct();
        $direction = $gearVec->getDirection();

        $positions = array();
        for ($i=0; $i<$gear-1; $i++) {

            $vecTop = $next->getAdjacentOctagons(1);
            $vector = new VektoraceVector2($vecTop, $direction, $i+1, 'top');
            $next = new VektoraceOctagon2($vecTop, $direction);

            $positions[] = array(
                'vecTopCoordinates' => $vecTop->coordinates(),
                'vecCenterCoordinates' => $vector->getCenter()->coordinates(),
                'length' => $i+1,
                'legal' => !self::detectCollision($vector,true),
                'carPosAvail' => self::argCarPlacement($vector, true)['hasValid']  // leagl/valid, as it also checks if this particular boost lenght produces at least one vaild position
            );
        }

        $hasValid = false;

        foreach ($positions as $pos) {
            if ($pos['legal'] && $pos['carPosAvail']) {
                $hasValid = true;
                break;
            }
        }

        return array('positions' => $positions, 'direction' => $direction, 'hasValid' => $hasValid);
    }

    // works as every positioning arguments method, but also adds information about rotation arrows placements (treated simply as adjacent octagons) and handles restriction on car possible directions and positioning
    function argCarPlacement($predictFromVector=null,$isPredBoost=false) {

        $gear = self::getPlacedVector();
        $boost = self::getPlacedVector('boost');

        $topAnchor;
        $n;
        $isBoost;

        if (!is_null($predictFromVector)) {
            $topAnchor = $predictFromVector->getTopOct();
            $n = $predictFromVector->getLength();
            $isBoost = $isPredBoost;
        } else {

            if (is_null($boost)) {
                $topAnchor = $gear->getTopOct();
                $n = $gear->getLength();
                $isBoost = false;  
            } else {
                $topAnchor = $boost->getTopOct();
                $n = $boost->getLength();
                $isBoost = true;
            }
        }

        $dir = $topAnchor->getDirection();

        $positions = array();
        $posNames = array('right-side','right','front','left','left-side');

        $id = self::getActivePlayerId();
        $deniedSide = self::getObjectFromDb("SELECT DeniedSideLeft L, DeniedSideRight R FROM penalities_and_modifiers WHERE player = $id");
        
        $playerCurve = self::getPlayerCurve($id);
        $playerCurveObj = new VektoraceCurb($playerCurve['number']);

        $pw = self::getPitwall();

        foreach ($topAnchor->getAdjacentOctagons(5) as $i => $carPos) {

            $carOct = new VektoraceOctagon2($carPos, $dir);
            $directions = array();
            $dirNames = array('right', 'straight', 'left');

            foreach ($carOct->getAdjacentOctagons(3) as $j => $arrowPos) {
                
                if (!($i==0 && $j==2) && !($i==4 && $j==0))
                    $directions[] = array(
                        'direction' => $dirNames[$j],
                        'coordinates' => $arrowPos->coordinates(),
                        'rotation' => $j-1,
                        'black' => $i==0 || $i==4 || ($i==1 && $j==2) || ($i==3 && $j==0)
                    );
            }

            $curveZoneStep = $carOct->getCurveZone($playerCurveObj) - $playerCurve['zone'];

            $positions[] = array(
                'position' => $posNames[$i],
                'coordinates' => $carPos->coordinates(),
                'directions' => $directions,
                'tireCost' => ($i==0 || $i==4) && !(($i < 2 && $deniedSide['R']) || ($i > 2 && $deniedSide['L'])),
                // messy stuff to passively tell why a position is denied. there are few special case to be aware of:
                //  position is both denied by shunk AND is a tireCost position -> position is displayed simply as DENIED (turn off tireCost, you'll see why this is necessary in client)
                //  position is tireCost AND player is restricted from selecting tireCost positions -> position is set also to denied and displayed as DENIED (but being both tireCost and denied true, client can guess why without additional info)
                //  position is only denied by shunk -> position is set and displayed as DENIED
                //  position is only tireCost -> position is set and displayed as TIRECOST
                //  position is both tireCost, NoBlackMov AND denied by shunk -> position is simply displayed as denied by shunk (no need to display additional info)
                'legal' => !self::detectCollision($carOct),
                'denied' => ($i < 2 && $deniedSide['R']) || ($i > 2 && $deniedSide['L']) || (($i==0 || $i==4) && self::getUniqueValueFromDb("SELECT NoBlackMov FROM penalities_and_modifiers WHERE player = $id")),
                'byFinishLine' => $carOct->inPitZone($pw, 'SoC') || $carOct->inPitZone($pw, 'grid'),
                'byBox' => $carOct->inPitZone($pw, 'box') || $carOct->inPitZone($pw, 'exit'),
                'offTrack' =>  $curveZoneStep > 3 || $curveZoneStep < -1 || ($curveZoneStep < 0 && $carOct->getCurveZone($playerCurveObj) == 0),
                'debug' => [
                    'prevZone' => $playerCurve['zone'],
                    'currZone' => $carOct->getCurveZone($playerCurveObj),
                    'step' => $curveZoneStep
                ],
                'curveProgress'=> $carOct->getCurveZone($playerCurveObj) // used by server only
            );
        }

        // hello, mess
        if ($n == 1 || $isBoost) {
            unset($positions[0]);
            unset($positions[4]);
        }

        if ($isBoost) {
            unset($positions[1]['directions'][2]);
            unset($positions[3]['directions'][0]);

            if($n>1) {
                unset($positions[1]['directions'][0]);
                unset($positions[3]['directions'][2]);
            }

            if($n>2) {
                unset($positions[1]);
                unset($positions[3]);
            }

            if($n>3) {
                unset($positions[2]['directions'][0]);
                unset($positions[2]['directions'][2]);
            }
        }

        $hasValid = false;

        // always easier to return non associative arrays for lists of positions, so that js can easly iterate through them
        $positions = array_values($positions);
        foreach ($positions as $i => $pos) {
            $positions[$i]['directions'] = array_values($positions[$i]['directions']);

            // enter only if:
            if ($pos['legal'] && // pos is legal
                !$pos['denied'] && // pos is not denied
                !$pos['offTrack'] && // pos is not off track
                // if pos is costs a tire, the player has at least one tire token and it's not prevented from using it
                !($pos['tireCost'] && (self::getPlayerTokens($id)['tire']<1 || self::getUniqueValueFromDb("SELECT NoBlackMov FROM penalities_and_modifiers WHERE player = $id"))) &&
                !(self::isPlayerAfterLastCurve($id) && (
                    ($pos['byFinishLine'] && self::getUniqueValueFromDb("SELECT BoxBox FROM penalities_and_modifiers WHERE player = $id")) ||
                    ($pos['byBox'] && ($isBoost || self::getPlayerCurve($id)['number'] == 1 || self::getPlayerLap($id) == self::getGameStateValue('number_of_laps')-1))
                ))
            )
                $hasValid = true;
        }

        return array('positions' => $positions, 'direction' => $dir, 'hasValid' => $hasValid);
    }

    /* 
    . drafting (no tire token used, min 3rd gear for both cars, same dir as enemy car, max 2 octagon distance from enemy car bottom)
    . slingshot pass (same as above, but only 1 oct max distance)
    . pushing (same as above)
    . shunting (min 2nd gear for player car only, same dir as enemy car, max 1 oct distance from enemey car bottom sides) 
    */
    function argAttackManeuvers() {

        $playerId = self::getActivePlayerId();
        $playerCar = self::getPlayerCarOctagon($playerId);

        $sql = "SELECT id
                FROM game_element
                WHERE entity = 'car' AND id != $playerId";
        $enemies = self::getObjectListFromDb($sql, true);

        $attEnemies = array();
        $canAttack = false;
        $movsAvail = false;

        // -- PLAYER CAN ATTACK CHECK
        $penalities = self::getObjectFromDb("SELECT NoDrafting, NoAttackMov, BoxBox FROM penalities_and_modifiers WHERE player = $playerId");
        if (self::getPlayerTurnPos($playerId) != 1 &&
            !$penalities['NoAttackMov'] && !$penalities['BoxBox'] &&
            !($playerCar->inPitZone(self::getPitwall(),'box','any'))
            ){
            $canAttack = true;
            
            foreach ($enemies as $enemyId) {
                
                $enemyCar = self::getPlayerCarOctagon($enemyId);

                $pw = self::getPitwall();

                // -- ENEMY CAN BE ATTACKED CHECK
                if (!self::getUniqueValueFromDb("SELECT BoxBox FROM penalities_and_modifiers WHERE player = $enemyId") && // enemy is not shielded by boxbox
                    !($enemyCar->inPitZone(self::getPitwall(),'box','any')) && // enemy is not in pitbox
                    $enemyCar->overtake($playerCar) && // enemy is in front of player
                    $playerCar->getDirection() == $enemyCar->getDirection() && // enemy has same direction of player
                    VektoracePoint2::distance($playerCar->getCenter(),$enemyCar->getCenter()) <= 3*VektoraceGameElement::getOctagonMeasures()['size'] // enemy is within an acceptable range to be able to be attacked
                    ){

                    // init maneuvers arr
                    $maneuvers = array();

                    // create drafting manevuers detectors
                    $range2detectorVec = new VektoraceVector2($enemyCar->getAdjacentOctagons(1,true), $enemyCar->getDirection(), 2, 'top');
                    $range1detectorOct = new VektoraceOctagon2($enemyCar->getAdjacentOctagons(1,true), $enemyCar->getDirection());
                    /* //solution for car nose landing between range 2 detector vec octagons (empty triangle). good?
                    $midrange2detectorOct = new VektoraceOctagon2($range2detectorVec->getCenter(), $enemyCar->getDirection()); // needed to detect car nose when is inside triangle between two octs of range 2 detector*/
                    $range1Collision = false;
                    $range2Collision = false;
                    
                    // -- DRAFTING MANEUVERS CONDITION CHECKS
                    if (!$penalities['NoDrafting'] &&
                        self::getPlayerCurrentGear($playerId) >= 3 &&
                        self::getPlayerCurrentGear($enemyId) >= 3
                        ){

                        if ($playerCar->collidesWith($range2detectorVec, 'car') && $playerCar->isBehind($range1detectorOct)) {
                            $range2Collision = true;

                            if ($playerCar->collidesWith($range1detectorOct, 'car'))
                                $range1Collision= true;
                        }/*  else if ($playerCar->collidesWith($midrange2detectorOct, 'car'))
                            $range2Collision = true; */
                    }

                    // -- CALC SLINGSHOT POSITIONS
                    $slingshotPos = array();

                    // slingshot pos are the 3 adjacent position in front of enemy car
                    $hasValid = false;
                    foreach ($enemyCar->getAdjacentOctagons(3) as $pos) {
                        $posOct = new VektoraceOctagon2($pos);
                        $valid = !self::detectCollision($posOct) && !$posOct->inPitZone($pw,'box');
                        if ($valid) $hasValid = true;
                        $slingshotPos[] = array(
                            'pos' => $pos->coordinates(),
                            'valid' => $valid
                        );
                    }

                    // if none is valid it could be that another car is already in front of it
                    // player can then position his car on either side of the car already in front
                    if (!$hasValid) {
                        // search db for car in front of enemy car
                        ['x'=>$x, 'y'=>$y] = $enemyCar->getAdjacentOctagons(1)->coordinates();
                        $frontCar = self::getObjectFromDb("SELECT * FROM game_element WHERE entity = 'car' AND pos_x = $x AND pos_y = $y");

                        // if found, calc new singshot positions
                        if (!is_null($frontCar)) {
                            $frontCar = new VektoraceOctagon2(new VektoracePoint2($x,$y), $enemyCar->getDirection());
                            $sidePos = $frontCar->getAdjacentOctagons(5);
                            $left = $sidePos[0];
                            $right = $sidePos[4];
                            $leftOct = new VektoraceOctagon2($left);
                            $valid = !self::detectCollision($leftOct) && !$posOct->inPitZone($pw,'box');
                            if ($valid) $hasValid = true;
                            
                            $slingshotPos[] = array(
                                'pos' => $left->coordinates(),
                                'valid' => $valid
                            );
                            $rightOct = new VektoraceOctagon2($right);
                            $valid = !self::detectCollision($rightOct) && !$posOct->inPitZone($pw,'box');
                            if ($valid) $hasValid = true;
                            
                            $slingshotPos[] = array(
                                'pos' => $right->coordinates(),
                                'valid' => $valid
                            );

                            if ($hasValid) {
                                $slingshotPos = array_slice($slingshotPos,3,2);
                            }
                        } // otherwise, leave it be. no slingshot position is valid (all positions collide with other game elements)
                    }

                    // ADD DRAFTING MANEUVER DATA TO ENEMY MANEUVERS ARRAY
                    $maneuvers['drafting'] = array(
                        'name' => clienttranslate('Drafting'),
                        'attPos' => $range1detectorOct->getCenter()->coordinates(),
                        'catchPos' => $range2detectorVec->getBottomOct()->getCenter()->coordinates(),
                        'vecPos' => $range2detectorVec->getCenter()->coordinates(),
                        'vecDir' => $enemyCar->getDirection(),
                        'active' => $range2Collision,
                        'legal'=> !self::detectCollision($range2detectorVec, false, array($playerId))
                    );
                    $maneuvers['push'] = array(
                        'name' => clienttranslate('Push'),
                        'attPos' => $range1detectorOct->getCenter()->coordinates(),
                        'active' => $range1Collision,
                        'legal'=> !self::detectCollision($range1detectorOct, false, array($playerId))
                    );
                    $maneuvers['slingshot'] = array(
                        'name' => clienttranslate('Slingshot'),
                        'attPos' => $slingshotPos,
                        'active' => $range1Collision,
                        'legal' => $hasValid && !self::detectCollision($range1detectorOct, false, array($playerId))
                    );

                    // create shunking manevuers detectors
                    $sidesCenters = $enemyCar->getAdjacentOctagons(3,true);
                    $leftsideDetectorOct = new VektoraceOctagon2($sidesCenters[0], $enemyCar->getDirection());
                    $rightsideDetectorOct = new VektoraceOctagon2($sidesCenters[2], $enemyCar->getDirection());
                    $leftCollision = false;
                    $rightCollision = false;

                    // SHUNKING MANEUVERS CONDITION CHECK
                    if (self::getPlayerCurrentGear($playerId) >= 2 && self::getPlayerCurrentGear($enemyId) >= 2) {
                        
                        if ($playerCar->collidesWith($leftsideDetectorOct, 'car') && $playerCar->isBehind($leftsideDetectorOct)) $leftCollision = $hasValidMovs = true;
                        if ($playerCar->collidesWith($rightsideDetectorOct, 'car') && $playerCar->isBehind($rightsideDetectorOct)) $rightCollision = $hasValidMovs = true;

                    }

                    // ADD SHUNKING MANEUVER DATA TO ENEMY MANEUVERS ARRAY
                    $maneuvers['leftShunk'] = array(
                        'name' => clienttranslate('Left Shunk'),
                        'attPos' => $leftsideDetectorOct->getCenter()->coordinates(),
                        'active' => $leftCollision,
                        'legal'=> !self::detectCollision($leftsideDetectorOct, false, array($playerId))
                    );
                    $maneuvers['rightShunk'] = array(
                        'name' => clienttranslate('Right Shunk'),
                        'attPos' => $rightsideDetectorOct->getCenter()->coordinates(),
                        'active' => $rightCollision,
                        'legal'=> !self::detectCollision($rightsideDetectorOct, false, array($playerId))
                    );

                    $hasValidMovs = false;

                    foreach ($maneuvers as $mov) {
                        if ($mov['active'] && $mov['legal']) {
                            $hasValidMovs = $movsAvail = true;
                        }
                    }

                    // ADD EVERYTHING TO ENEMY ARRAY
                    $attEnemies[] = array(
                        'id' => $enemyId,
                        'coordinates' => $enemyCar->getCenter()->coordinates(),
                        'maneuvers' => $maneuvers,
                        'hasValidMovs' => $hasValidMovs
                    );
                }
            }
        }

        return array(
            "attEnemies" => $attEnemies,
            "attMovsAvail" => $movsAvail,
            "canAttack" => $canAttack,
            "playerCar" => array(
                "pos" => $playerCar->getCenter()->coordinates(),
                "dir" => $playerCar->getDirection(),
                "size" => array(
                    "width" => VektoraceGameElement::getOctagonMeasures()["size"],
                    "height" => VektoraceGameElement::getOctagonMeasures()["side"]
                )
            )
        );
    }

    function argPitStop() {

        $id = self::getActivePlayerId();
        $currGear = self::getPlayerCurrentGear($id);
        $speedSurplus = $currGear - 2;
        $amount = 8 - max($speedSurplus*2, 0);

        $tokens = self::getPlayerTokens($id);

        return array('tire' => $tokens['tire'], 'nitro' => $tokens['nitro'], 'amount' => $amount);
    }

    // return current gear.
    function argFutureGearDeclaration() {

        // if player in box, he might only choose the 2nd gear
        if (self::getPlayerCarOctagon(self::getActivePlayerId())->inPitZone(self::getPitwall(),'box')) return array('gears' => array('unavail','curr','unavail','unavail','unavail'));

        $curr = self::getPlayerCurrentGear(self::getActivePlayerId());
        $noShift = self::getObjectFromDb("SELECT NoShiftUp up, NoShiftDown down FROM penalities_and_modifiers WHERE player = ".self::getActivePlayerId());

        $gears = array();
        for ($i=0; $i<5; $i++) { 
            switch ($i+1 <=> $curr) {

                case -1: 
                    if ($noShift['down']) $gears[] = 'denied';
                    else $gears[] = ($i+1 - $curr == -1)? 'avail' : 'tireCost'; // if downshift is greater than 1, gear selection costs +1 tire token (for each step down)
                    break;

                case 0: $gears[] = 'curr';
                    break;

                case 1: 
                    if ($noShift['up']) $gears[] = 'denied';
                    else $gears[] = ($i+1 - $curr == 1)? 'avail' : 'nitroCost'; // if upshift is greater than 1, gear selection costs +1 nitro token (for each step up)
                    break;
            }
        }

        return array('gears' => $gears);
    }

    #endregion

    //++++++++++++++++++++++++//
    // STATE ACTION FUNCTIONS //
    //++++++++++++++++++++++++//
    #region state actions

    // [function called when entering a state (that specifies it) to perform some kind of action]
    
    // gives turn to next player for car positioning or jumps to green light phase
    function stNextPositioning() {
        $player_id = self::getActivePlayerId();
        $next_player_id = self::getPlayerAfter($player_id); // error?

        $this->gamestate->changeActivePlayer($next_player_id);

        $np_turnpos = self::getPlayerTurnPos($next_player_id);

        // if next player is first player
        if ($np_turnpos == 1) {
            $this->gamestate->nextState('gameStart');
        } else {
            // else, keep positioning

            $this->gamestate->nextState('nextPositioningPlayer');
        }
    }

    function stGearVectorPlacement() {

        $id = self::getActivePlayerId();

        self::incStat(1,'turns_number');
        self::incStat(1,'turns_number',$id);

        if (self::getPlayerTurnPos($id) == 1) self::incStat(1,'pole_turns',$id);

        $this->giveExtraTime($id);
    }

    function stBoostVectorPlacement() {
        if (!self::argBoostVectorPlacement()['hasValid']) {

            self::notifyAllPlayers('noBoostAvail', clienttranslate('${player_name} could not place any boost vector'), array(
                'player_name' => self::getActivePlayerName(),
                'player_id' => self::getActivePlayerId(),
            ));

            $this->gamestate->nextState();
        }
    }

    function stEmergencyBrake() {

        $id = self::getActivePlayerId();
        self::incStat(1,'brake_number',$id);

        $shiftedGear = self::getPlayerCurrentGear($id) -1;
        $tireExpense = 1;
        $insuffTokens = false;

        while ($shiftedGear > 0) {
            self::dump('// TRYING VECTOR ',$shiftedGear);

            $gearPlacement = self::argGearVectorPlacement($shiftedGear);
            self::dump('// PLACE VECTOR ARGS',$gearPlacement);
            if ($gearPlacement['hasValid']) {

                // CHECK FOR AVAILABLE TOKENS AND UPDATE AMOUNT
                $tireTokens = self::getPlayerTokens($id)['tire'] - $tireExpense;

                // if tokens insufficent break loop, car will simply stop. mem bool val to notify player reason
                if ($tireTokens < 0) {
                    self::trace('// INSUFF TOKENS');
                    $insuffTokens = true;
                    break;
                }

                self::DbQuery("UPDATE player SET player_tire_tokens = $tireTokens WHERE player_id = $id");
                self::incStat($tireExpense,'tire_used',$id);

                // UPDATE NEW GEAR
                $sql = "UPDATE player
                        SET player_current_gear = $shiftedGear
                        WHERE player_id = $id";
                self::DbQuery($sql);

                // NOTIFY PLAYERS
                self::notifyAllPlayers('useNewVector', clienttranslate('${player_name} slowed down to the ${shiftedGear}th gear, spending ${tireExpense} TireTokens'), array(
                    'player_name' => self::getActivePlayerName(),
                    'player_id' => $id,
                    'shiftedGear' => $shiftedGear,
                    'tireExpense' => $tireExpense
                ));

                // JUMP BACK TO VECTOR PLACEMENT PHASE
                $this->gamestate->nextState('slowdown');
                return;
            }

            $tireExpense ++;
            $shiftedGear --;
        } // if reaches 0 then car will completly stot (not move for one turn)

        self::DbQuery("UPDATE penalities_and_modifiers SET CarStop = 1 WHERE player = ".self::getActivePlayerId());

        // car will start next turn on gear 1
        $sql = "UPDATE player
                SET player_current_gear = 1
                WHERE player_id = ".self::getActivePlayerId();
        
        self::DbQuery($sql);

        $this->gamestate->nextState('brake');
        return;

        // a rotation is still allowed, so state does not jump (args contain rotation arrows data)
    }

    function stGiveWay() {
        $id = self::getActivePlayerId();
        $playerTurnPos = self::getPlayerTurnPos($id);
        $enemyTurnPos = $playerTurnPos + 1;
        $enemy = self::getPlayerTurnPosNumber($enemyTurnPos);

        self::DbQuery("UPDATE player SET player_turn_position = $enemyTurnPos WHERE player_id = $id");
        self::DbQuery("UPDATE player SET player_turn_position = $playerTurnPos WHERE player_id = $enemy");

        $this->gamestate->changeActivePlayer($enemy);
        $this->gamestate->nextState();
    }

    function stAttackManeuvers() {

        $args = self::argAttackManeuvers();

        if (count($args['attEnemies']) == 0) {
            $this->gamestate->nextState('noManeuver');
            return;
        }

        if (!$args['canAttack']) {
            if (self::getPlayerTurnPos(self::getActivePlayerId()) != 1)
                self::notifyAllPlayers('noAttMov', clienttranslate('${player_name} is restricted from performing any attack move this turn.'), (array(
                    'player_name' => self::getActivePlayerName(),
                    'player_id' => self::getActivePlayerId(),
                    'enemies' => 0
                )));
            $this->gamestate->nextState('noManeuver');
        } else if (!$args['attMovsAvail']) {
            if (self::getPlayerTurnPos(self::getActivePlayerId()) != 1)
                self::notifyAllPlayers('noAttMov', clienttranslate('${player_name} could not perform any valid attack move this turn.'), array(
                    'player_name' => self::getActivePlayerName(),
                    'player_id' => self::getActivePlayerId(),
                    'enemies' => count($args['attEnemies'])
                ));

            $this->gamestate->nextState('noManeuver');
        }
    }

    function stPitStop() {

        $id = self::getActivePlayerId();
        $currGear = self::getPlayerCurrentGear($id);
        $speedSurplus = $currGear - 2;

        if ($currGear > 2) {
            self::notifyAllPlayers('boxSpeedPenality', clienttranslate('${player_name} exceeded pit box entrance speed limit by ${speedSurplus} (-${penality} refilled tokens)'), array(
                'player_name' => self::getActivePlayerName(),
                'player_id' => self::getActivePlayerId(),
                'speedSurplus' => $speedSurplus,
                'penality' =>  $speedSurplus*2
            ));
        }
    }

    function stEndOfMovementSpecialEvents() {

        // store some useful vars
        $id = self::getActivePlayerId();
        $playerCar = self::getPlayerCarOctagon($id);
        $playerCurve = self::getPlayerCurve($id);

        $playerCurveNumber = $playerCurve['number'];
        $nextCurveNumber = $playerCurve['next'];

        $curveZone = $playerCurve['zone'];

        self::dump("// PLAYER CURVE DUMP", [
            'curveNum' => $playerCurveNumber,
            'curveZone' => $curveZone,
            'nextCurve' => $nextCurveNumber
        ]);

        $playerCurve = new VektoraceCurb($playerCurveNumber);
        $nextCurve = new VektoraceCurb($nextCurveNumber);

        // CHECK IF CURRENT CURVE IS NOT LAST
        if ($nextCurveNumber != 1) {
            // if so, check if player reached reached and passed next curve

            // curve passed check (COULD BE BETTER?)
            // if car has left zone 4 of a curve, then it is considered to have passed and assigned the next curve as current one, indipendently of distance to that curve
            // if car is closer to next curve (but still hasn't passed 4th zone), assign car to next curve anyway (this is for when curves don't from a convex track shape)
            if ($curveZone > 4 || 
                VektoracePoint2::distance($playerCar->getCenter(), $nextCurve->getCenter()) < VektoracePoint2::distance($playerCar->getCenter(), $playerCurve->getCenter())) {

                // set new curve db
                self::DbQuery("UPDATE player SET player_curve_number = $nextCurveNumber WHERE player_id = $id");
                $playerCurveNumber = $nextCurveNumber;

                // calc new curve zone
                $curveZone = $playerCar->getCurveZone($nextCurve);
                if ($curveZone > 3) $curveZone = 0; // if curve zone is higher than 3 (likely 7, meaning behind curve, in rare situation where curves are far from each other and pointing in different directions)

                // set new curve zone
                self::DbQuery("UPDATE player SET player_curve_zone = $curveZone WHERE player_id = $id");
            }
        } else if ($curveZone > 4) {

            self::trace("// PASSED LAST CURVE");

            // -- check finish line crossing
            // dot prod of vector pointing from pitwall top to its direction and vector pointing to nose of player car
            $pw = self::getPitwall();
            $pwProp = $pw->getProperties();
            $pwVec = $pwProp['c'];
            $pwFinishPoint = $pwProp['Q'];
            $carNose = $playerCar->getDirectionNorm()['origin'];
            $carVec = VektoracePoint2::displacementVector($pwFinishPoint, $carNose);

            // car crosses line if is parallel to piwall AND if it's nose crosses the line
            if (VektoracePoint2::dot($pwVec,$carVec) > 0) {
                self::DbQuery("UPDATE player SET player_lap_number = player_lap_number+1 WHERE player_id = $id");
                
                // update playter lap number
                $playerLapNum = self::getUniqueValueFromDb("SELECT player_lap_number FROM player WHERE player_id = $id");
                // check if player lap number is same provided by game options 
                if ($playerLapNum == self::getGameStateValue("number_of_laps")) {
                    // if so race should end for this player
                    $score = self::getPlayersNumber() - self::getPlayerTurnPos($id);
                    self::DbQuery("UPDATE player SET player_score = $score WHERE player_id = $id");
                    self::DbQuery("UPDATE penalities_and_modifiers SET FinishedRace = 1 WHERE player = $id");
                    self::DbQuery("DELETE FROM game_element WHERE id = $id");
                    self::notifyAllPlayers('finishedRace',clienttranslate('${player_name} crossed the finish line in ${pos} position'), array(
                        'player_name' => self::getActivePlayerName(),
                        'player_id' => $id,
                        'pos' => self::getPlayerTurnPos($id),
                        'lapNum' => $playerLapNum
                    ));

                    if (self::getPlayerTurnPos($id) == self::getPlayersNumber()-1) $this->gamestate->nextState('raceEnd');
                    else $this->gamestate->nextState('skipGearDeclaration');

                    return;
                        
                } else {

                    // send notif about player completing a lap
                    self::notifyAllPlayers('lapFinish',clienttranslate('${player_name} completed their ${n} lap'), array(
                        'player_name' => self::getActivePlayerName(),
                        'player_id' => $id,
                        'n' => $playerLapNum
                    ));

                    // reset boxbox
                    self::DbQuery("UPDATE penalities_and_modifiers SET BoxBox = NULL WHERE player = $id");

                    // set new curve db
                    self::DbQuery("UPDATE player SET player_curve_number = $nextCurveNumber WHERE player_id = $id"); // next curve number should be 1

                    // calc new curve zone
                    $curveZone = $playerCar->getCurveZone($nextCurve);
                    if ($curveZone > 3) $curveZone = 0; // i curve zone greater than 3 it's likely player curve is far and facing a weird direction. set zone to 0 to avoid misdetection of offroad

                    // set new curve zone
                    self::DbQuery("UPDATE player SET player_curve_zone = $curveZone WHERE player_id = $id");
                }
            } else { // else, if finish line has not been crossed

                // and if player hasn't decided on calling boxbox
                if (is_null(self::getUniqueValueFromDb("SELECT BoxBox FROM penalities_and_modifiers WHERE player = $id")) && self::getPlayerLap($id) < self::getGameStateValue('number_of_laps')-1) {

                    // go to boxbox promt state
                    $this->gamestate->nextState('boxBox');
                    return;
                }
            }
        }

        $this->gamestate->nextState('gearDeclaration');
    }

    // gives turn to next player for car movement or recalculates turn order if all player have moved their car
    function stNextPlayer() {
        $player_id = self::getActivePlayerId();

        self::DbQuery(
            "UPDATE penalities_and_modifiers 
            SET NoBlackMov = 0,
                NoShiftDown = 0,
                NoShiftUp = 0,
                CarStop = 0,
                NoAttackMov = 0,
                NoDrafting = 0,
                DeniedSideLeft = 0,
                DeniedSideRight = 0
            WHERE player = $player_id");

        $np_id = self::getPlayerAfterCustom($player_id);
        
        if (self::getPlayerTurnPos($np_id) == 1) {

            $order = self::newTurnOrder();
            
            $optString = '';
            if ($order['isChanged']) $optString = ' The turn order has changed.';

            self::notifyAllPlayers('nextRoundTurnOrder', clienttranslate('A new game round begins.'.$optString), array(
                'order' => $order['list'],
                'missingPlayers' => self::getPlayersNumber() - count($order['list'])
            ));

            self::incGameStateValue('turn_number', 1);

            $np_id = self::getPlayerTurnPosNumber(1);

        }

        while (self::isPlayerRaceFinished($np_id)) {
            $np_id = self::getPlayerAfterCustom($np_id);
        }

        $this->gamestate->changeActivePlayer($np_id);

        $this->gamestate->nextState();
    }

    #endregion

    //+++++++++++++++//
    // ZOMBIE SYSTEM //
    //+++++++++++++++//
    #region zombie

    // [advance stuff for when a player quit]

    /* zombieTurn:
     *
     * This method is called each time it is the turn of a player who has quit the game (= "zombie" player).
     * You can do whatever you want in order to make sure the turn of this player ends appropriately
     * (ex: pass).
     * 
     * Important: your zombie code will be called when the player leaves the game. This action is triggered
     * from the main site and propagated to the gameserver from a server, not from a browser.
     * As a consequence, there is no current player associated to this action. In your zombieTurn function,
     * you must _never_ use getCurrentPlayerId() or getCurrentPlayerName(), otherwise it will fail with a "Not logged" error message.
     */
    function zombieTurn($state, $active_player) {
    	$statename = $state['name'];
    	
        if ($state['type'] === "activeplayer") {
            switch ($statename) {
                default:
                    $this->gamestate->nextState( "zombiePass" );
                	break;
            }

            return;
        }

        if ($state['type'] === "multipleactiveplayer") {
            // Make sure player is in a non blocking status for role turn
            $this->gamestate->setPlayerNonMultiactive( $active_player, '' );
            
            return;
        }

        throw new feException( "Zombie mode not supported at this game state: ".$statename );
    }

    #endregion
    
    //+++++++++++++++++++//
    // DB VERSION UPDATE //
    //+++++++++++++++++++//
    #region db update

    /* upgradeTableDb:
     * 
     * You don't have to care about this until your game has been published on BGA.
     * Once your game is on BGA, this method is called everytime the system detects a game running with your old
     * Database scheme.
     * In this case, if you change your Database scheme, you just have to apply the needed changes in order to
     * update the game database and allow the game to continue to run with your new version.
     */   
    function upgradeTableDb($from_version) {
        // $from_version is the current version of this game database, in numerical form.
        // For example, if the game was running with a release of your game named "140430-1345",
        // $from_version is equal to 1404301345
        
    /* Example:
     *  if( $from_version <= 1404301345 ) {
     *      // ! important ! Use DBPREFIX_<table_name> for all tables
     *
     *      $sql = "ALTER TABLE DBPREFIX_xxxxxxx ....";
     *      self::applyDbUpgradeToAllDB( $sql );
     *  }
     *  
     * if( $from_version <= 1405061421 ) {
     *      // ! important ! Use DBPREFIX_<table_name> for all tables
     *
     *      $sql = "CREATE TABLE DBPREFIX_xxxxxxx ....";
     *      self::applyDbUpgradeToAllDB( $sql );
     *  }
     *  // Please add your future database scheme changes here
     */

    }  
    
    #endregion
}
