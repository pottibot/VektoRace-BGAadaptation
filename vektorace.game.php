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
require_once('modules/VektoraceOctagon.php');
require_once('modules/VektoraceVector.php');
require_once('modules/VektoracePoint.php');

class VektoRace extends Table {
    
    //+++++++++++++++++++++//
    // SETUP AND DATA INIT //
    //+++++++++++++++++++++//
    #region setup

	function __construct() {
        parent::__construct();
        
        // GAME.PHP GLOBAL VARIABLES HERE
        // they are not simple global variables as those are destroyed with every page load
        // it's a sort of array stored separately (for more info see doc)
        // if not strictly necessary use DB to store every information about the game
        self::initGameStateLabels( array( ));        
	}
	
    // getGameName: basic utility method used for translation and other stuff. do not modify
    protected function getGameName() {
        return "vektorace";
    }	

    // setupNewGame: called once, when a new game is initialized. this sets the initial game state according to the rules
    protected function setupNewGame( $players, $options=array()) {

        self::loadTrackPreset(); // custom function to set predifined track model

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
        }

        $sql .= implode( $values, ',' );
        self::DbQuery( $sql );

        $sql = "UPDATE player
                SET player_turn_position = player_no";
        self::DbQuery($sql);        

        self::reattributeColorsBasedOnPreferences( $players, $gameinfos['player_colors'] );
        self::reloadPlayersBasicInfos();

        // --- INIT GLOBAL VARIABLES ---
        // example: self::setGameStateInitialValue( 'my_first_global_variable', 0 );
        
        // --- INIT GAME STATISTICS ---
        // example: (statistics model should be first defined in stats.inc.php file)
        // self::initStat( 'table', 'table_teststat1', 0 );    // Init a table statistics
        // self::initStat( 'player', 'player_teststat1', 0 );  // Init a player statistics (for all players)

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

        $result['octagon_ref'] = VektoraceOctagon::getOctProperties();

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
        // TODO
        return 0;
    }

    #endregion

    //+++++++++++++++++++//
    // UTILITY FUNCTIONS //
    //+++++++++++++++++++//
    #region utility

    // [general purpose function that controls the game logic]

    // test: test function to put whatever comes in handy at a given time
    function testComponent() {

        /* $ret = array();

        foreach (self::getObjectListFromDb("SELECT * FROM game_element") as $element) {
            switch ($element['entity']) {
                case 'car':
                    if ($element['pos_x']!=null && $element['pos_y']!=null) {
                        $car = new VektoraceOctagon(new VektoracePoint($element['pos_x'],$element['pos_y']),$element['orientation']);
                        $vertices = $car->getVertices();

                        foreach ($vertices as &$v) {
                            $v = $v->coordinates();
                        } unset($v);

                        $ret['car'.' '.$element['id']] = $vertices;
                    }
                    break;

                case 'curve':
                    $curve = new VektoraceOctagon(new VektoracePoint($element['pos_x'],$element['pos_y']),$element['orientation'],true);
                    $vertices = $curve->getVertices();

                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v);

                    $ret['curve'.' '.$element['id']] = $vertices;
                    break;

                case 'pitwall':
                    $pitwall = new VektoraceVector(new VektoracePoint($element['pos_x'],$element['pos_y']),$element['orientation'],4);

                    $topOct = $pitwall->getTopOct()->getVertices();
                    $innerRect = $pitwall->innerRectVertices();
                    $bottomOct =  $pitwall->getBottomOct()->getVertices();

                    $vertices = array_merge($topOct, $innerRect, $bottomOct);

                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v);

                    $ret['pitwall'] = $vertices;
                    break;

                default: // vectors and boosts
                    $vector = new VektoraceVector(new VektoracePoint($element['pos_x'],$element['pos_y']),$element['orientation'],$element['id']);

                    $topOct = $vector->getTopOct()->getVertices();
                    $innerRect = $vector->innerRectVertices();
                    $bottomOct =  $vector->getBottomOct()->getVertices();

                    $vertices = array_merge($topOct, $innerRect, $bottomOct);
<<<<<<< Updated upstream

                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v);

                    $ret[$element['entity'].' '.$element['id']] = $vertices;
                    break;
            }
        }

        self::consoleLog($ret); */

        /* $oct = new VektoraceOctagon(new VektoracePoint(100,100));
        $vector = new VektoraceVector(new VektoracePoint(0,0), 4, 4);

        $collision = $oct->collidesWithVector($vector);

        self::consoleLog(array('collision' => $collision)); */

        // get all cars pos from db
        $sql = "SELECT player_id id, pos_x x, pos_y y, orientation dir
                FROM player
                JOIN game_element ON id = player_id
                WHERE entity = 'car'
                ORDER BY player_turn_position DESC";

        $allPlayers = self::getObjectListFromDB($sql);

        $oldOrder = $allPlayers;

        // we need to return boolan to indicate if position changed since last turn 
        $isChanged = false;


        // bubble sort cars using overtake conditions
        for ($i=0; $i<count($allPlayers)-1; $i++) { 
            for ($j=0; $j<count($allPlayers)-1-$i; $j++) {
                $thisPlayer = $allPlayers[$j];
                $playerCar = new VektoraceOctagon(new VektoracePoint($thisPlayer['x'], $thisPlayer['y']), $thisPlayer['dir']);

                $nextPlayer = $allPlayers[$j+1];
                $nextCar = new VektoraceOctagon(new VektoracePoint($nextPlayer['x'], $nextPlayer['y']), $nextPlayer['dir']);
                
                if ($playerCar->overtake($nextCar)) {
                    $isChanged = true;

=======

                    foreach ($vertices as &$v) {
                        $v = $v->coordinates();
                    } unset($v);

                    $ret[$element['entity'].' '.$element['id']] = $vertices;
                    break;
            }
        }

        self::consoleLog($ret); */

        /* $oct = new VektoraceOctagon(new VektoracePoint(100,100));
        $vector = new VektoraceVector(new VektoracePoint(0,0), 4, 4);

        $collision = $oct->collidesWithVector($vector);

        self::consoleLog(array('collision' => $collision)); */

        // get all cars pos from db
        $sql = "SELECT player_id id, pos_x x, pos_y y, orientation dir
                FROM player
                JOIN game_element ON id = player_id
                WHERE entity = 'car'
                ORDER BY player_turn_position DESC";

        $allPlayers = self::getObjectListFromDB($sql);

        $oldOrder = $allPlayers;

        // we need to return boolan to indicate if position changed since last turn 
        $isChanged = false;


        // bubble sort cars using overtake conditions
        for ($i=0; $i<count($allPlayers)-1; $i++) { 
            for ($j=0; $j<count($allPlayers)-1-$i; $j++) {
                $thisPlayer = $allPlayers[$j];
                $playerCar = new VektoraceOctagon(new VektoracePoint($thisPlayer['x'], $thisPlayer['y']), $thisPlayer['dir']);

                $nextPlayer = $allPlayers[$j+1];
                $nextCar = new VektoraceOctagon(new VektoracePoint($nextPlayer['x'], $nextPlayer['y']), $nextPlayer['dir']);
                
                if ($playerCar->overtake($nextCar)) {
                    $isChanged = true;

>>>>>>> Stashed changes
                    $temp = $allPlayers[$j+1];
                    $allPlayers[$j+1] = $allPlayers[$j];
                    $allPlayers[$j] = $temp;
                }
            }
        }

        self::consoleLog(array('oldOrder' => $oldOrder, 'newOrder' => $allPlayers, 'hasChanged' => $isChanged));
    }
    
    // consoleLog: debug function that uses notification to log various element to js console (CAUSES BGA FRAMEWORK ERRORS)
    function consoleLog($payload) {
        self::notifyAllPlayers('logger','i have logged',$payload);
    }

    // loadTrackPreset: sets DB to match a preset of element of a test track
    function loadTrackPreset() {
        $sql = "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                VALUES ('pitwall',10,0,0,4),
                       ('curve',1,-800,500,4),
                       ('curve',2,800,500,0)";
        self::DbQuery($sql);
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

        $round = [4,1,2,3];
        $next = ($playerTurnPos + 1) % self::getPlayersNumber();

        return self::getPlayerTurnPosNumber($round[$next]);
    }

    // getPlayerCarOctagon: returns VektoraceOctagon object created at center pos, given by the coordinates of the players car, according to DB
    function getPlayerCarOctagon($id) {

        $sql = "SELECT pos_x, pos_y, orientation
                FROM game_element
                WHERE entity = 'car' AND id = $id";

        $ret = self::getObjectFromDB($sql);
        return new VektoraceOctagon(new VektoracePoint($ret['pos_x'],$ret['pos_y']), $ret['orientation']);
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
        return new VektoraceVector(new VektoracePoint($ret['pos_x'],$ret['pos_y']), $ret['orientation'], $ret['id']);
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
        $sql = "SELECT player_id id, pos_x x, pos_y y, orientation dir
                FROM player
                JOIN game_element ON id = player_id
                WHERE entity = 'car'
                ORDER BY player_turn_position DESC";

        $allPlayers = self::getObjectListFromDB($sql);

        // we need to return boolan to indicate if position changed since last turn 
        $isChanged = false;
<<<<<<< Updated upstream


        // bubble sort cars using overtake conditions
        for ($i=0; $i<count($allPlayers)-1; $i++) { 
            for ($j=0; $j<count($allPlayers)-1-$i; $j++) {
                $thisPlayer = $allPlayers[$j];
                $playerCar = new VektoraceOctagon(new VektoracePoint($thisPlayer['x'], $thisPlayer['y']), $thisPlayer['dir']);

=======


        // bubble sort cars using overtake conditions
        for ($i=0; $i<count($allPlayers)-1; $i++) { 
            for ($j=0; $j<count($allPlayers)-1-$i; $j++) {
                $thisPlayer = $allPlayers[$j];
                $playerCar = new VektoraceOctagon(new VektoracePoint($thisPlayer['x'], $thisPlayer['y']), $thisPlayer['dir']);

>>>>>>> Stashed changes
                $nextPlayer = $allPlayers[$j+1];
                $nextCar = new VektoraceOctagon(new VektoracePoint($nextPlayer['x'], $nextPlayer['y']), $nextPlayer['dir']);
                
                if ($playerCar->overtake($nextCar)) {
                    $isChanged = true;

                    $temp = $allPlayers[$j+1];
                    $allPlayers[$j+1] = $allPlayers[$j];
                    $allPlayers[$j] = $temp;
                }
            }
        }

        foreach($allPlayers as $i => $player) {
            $sql = "UPDATE player
                    SET player_turn_position = ".(count($allPlayers)-intval($i))."
                    WHERE player_id = ".$player['id'];
            self::DbQuery($sql);
        }

        return $isChanged;
    }

    // big messy method checks if subj object (can be either octagon or vector) collides with any other element on the map (cars, curves or pitwall)
    function detectCollision($subj, $isVector=false) {

<<<<<<< Updated upstream
        self::dump('/// ANALIZING COLLISION OF '.(($isVector)? 'VECTOR':'CAR POSITION'),$subj->getCenter()->coordinates());
=======
        // self::dump('/// ANALIZING COLLISION OF '.(($isVector)? 'VECTOR':'CAR POSITION'),$subj->getCenter()->coordinates());
>>>>>>> Stashed changes

        foreach (self::getObjectListFromDb("SELECT * FROM game_element") as $element) {

            if ($element['id']!=self::getActivePlayerId() && !is_null($element['pos_x']) && !is_null($element['pos_y'])) {

                $pos = new VektoracePoint($element['pos_x'],$element['pos_y']);

<<<<<<< Updated upstream
                self::dump('// WITH '.$element['entity'].' '.$element['id'].' AT ', $pos->coordinates());
=======
                // self::dump('// WITH '.$element['entity'].' '.$element['id'].' AT ', $pos->coordinates());
>>>>>>> Stashed changes

                if ($isVector) {

                    // this pitwall case is problematic as it it vector to vector collision
                    if ($element['entity']=='pitwall') {

                        $pitwall = new VektoraceVector($pos, $element['orientation'], 4);

                        // PITWALL COLLIDES WITH EITHER THE TOP OR BOTTOM VECTOR'S OCTAGON AND VICE VERSA

                        if ($subj->getBottomOct()->collidesWithVector($pitwall) || $subj->getTopOct()->collidesWithVector($pitwall)) { return true; }
                        if ($pitwall->getBottomOct()->collidesWithVector($subj) || $pitwall->getTopOct()->collidesWithVector($subj)) { return true; }

                        // PITWALL INNER RECTANGLE COLLIDES WITH VECTOR INNER RECTANGLE (RARE)

                        $pitwallInnerRect = $pitwall->innerRectVertices();
                        $vectorInnerRect = $subj->innerRectVertices();

                        //self::consoleLog(array('pitwall' => $pitwallInnerRect, 'vector' => $vectorInnerRect));

                        if (!VektoraceOctagon::findSeparatingAxis($pitwallInnerRect, $vectorInnerRect)) {

                            $omg = M_PI_4;
                            foreach ($pitwallInnerRect as &$vertex) {
                                $vertex->rotate($omg);
                            }
                            unset($vertex);
    
                            foreach ($vectorInnerRect as &$vertex) {
                                $vertex->rotate($omg);
                            }
                            unset($vertex);
    
                            if (!VektoraceOctagon::findSeparatingAxis($pitwallInnerRect, $vectorInnerRect)) return true;
                        }

                    } else {

                        $obj = new VektoraceOctagon($pos, $element['orientation'], $element['entity']=='curve');
<<<<<<< Updated upstream

                        self::dump('/ DUMPING OBJ',$obj);
                        self::dump('/ DUMPING SUBJ',$subj);

                        $bottom = $subj->getBottomOct();

                        self::dump('WHAT IS THIS ',$bottom);

=======
>>>>>>> Stashed changes
                        if ($obj->collidesWithVector($subj)) return true; 
                    }

                } else {

                    if ($element['entity']=='pitwall') {
                        $obj = new VektoraceVector($pos, $element['orientation'], 4);
                        if ($subj->collidesWithVector($obj)) return true;
                    }

                    $obj = new VektoraceOctagon($pos, $element['orientation'], $element['entity']=='curve');
                    if ($subj->collidesWith($obj)) return true;
                }
            }
        }

        return false;
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

            $center = new VektoracePoint($args['center']['x'],$args['center']['y']);
            $norm = new VektoracePoint(0,0);
            $norm->translateVec(1,($dir)*M_PI_4);

            $pos = VektoracePoint::displacementVector($center, new VektoracePoint($x,$y));
            $pos->normalize();

            if (abs(VektoracePoint::dot($norm, $pos)) > 0.1) throw new BgaUserException('Invalid car position');

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

            $pos = $args['positions'][$refCarId]['positions'][$posIdx];

            if (!$pos['valid']) throw new BgaUserException('Invalid car position');

            ['x'=>$x,'y'=>$y] = $pos['coordinates'];

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
            ));

            $this->gamestate->nextState();
        }
    }

    function chooseTokensAmount($tire,$nitro) {
        if ($this->checkAction('chooseTokensAmount')) {

            $args = self::argTokenAmountChoice();
            if ($tire > 8 || $nitro > 8 || ($tire+$nitro) != min($args['tire'] + $args['nitro'] + $args['amount'], 16)) throw new BgaUserException('Invalid tokens amount');

            $id = self::getActivePlayerId();

            $sql = "UPDATE player
                    SET player_tire_tokens = $tire, player_nitro_tokens = $nitro
                    WHERE player_id = $id";

            self::DbQuery($sql);

            self::notifyAllPlayers('chooseTokensAmount', clienttranslate('${player_name} chose to start the game with ${tire} TireTokens and ${nitro} NitroTokens'), array(
                'player_id' => $id,
                'player_name' => self::getActivePlayerName(),
                'tire' => $tire,
                'nitro' => $nitro
                )
            );

            $this->gamestate->nextState();
        }
    }

    // chooseStartingGear: server function responding to user input when a player chooses the gear vector for all players (green-light phase)
    function chooseStartingGear($n) {
        if ($this->checkAction('chooseStartingGear')) {

            if ($n<3 && $n>0) throw new BgaUserException('You may only choose between the 3rd to the 5th gear for the start of the game');
            if ($n<3 || $n>5) throw new BgaUserException('Invalid gear number');

            $sql = "UPDATE player
                    SET player_current_gear = $n";
        
            self::DbQuery($sql);

            self::notifyAllPlayers('chooseStartingGear', clienttranslate('${player_name} chose the ${n}th gear as the starting gear for every player'), array(
                'player_name' => self::getActivePlayerName(),
                'n' => $n,
                ) 
            );
        }

        $this->gamestate->nextState();
    }

    // declareGear: same as before, but applies only to active player, about his gear of choise for his next turn. thus DB is updated only for the player's line
    // TODO: CHECK THATH PLAYER MAY ACTUALLY CHOOSE THAT GEAR NUMBER (ie. the gear shift is not greater than 1)
    //       AND IMPLMENET TIRE AND NITRO TOKEN TO PURCHASE LARGER GEAR SHIFTS
    function declareGear($n) {
        if ($this->checkAction('declareGear')) {

            if ($n<0 || $n>5) throw new BgaUserException('Invalid gear number');

            $id = self::getActivePlayerId();

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
        }

        $this->gamestate->nextState();
    }

    function placeGearVector($position) {

        if ($this->checkAction('placeGearVector')) {

            foreach (self::argGearVectorPlacement()['positions'] as $pos) {

                if ($pos['position'] == $position) {

                    $id = self::getActivePlayerID();

                    $orientation = self::getUniqueValueFromDb("SELECT orientation FROM game_element WHERE id=$id");
                    $gear = self::getPlayerCurrentGear($id);
                    ['x'=>$x, 'y'=>$y] = $pos['vectorCoordinates'];

                    $sql = "INSERT INTO game_element (entity, id, pos_x, pos_y, orientation)
                            VALUES ('gearVector', $gear, $x, $y, $orientation)";
                    self::DbQuery($sql);

                    $tireTokens = self::getPlayerTokens($id)['tire'];

                    $optString = '';

                    if ($pos['tireCost']) {

                        if ($tireTokens == 0) throw new BgaUserException(self::_("You don't have enough Tire Tokens to do this move"));
                        
                        $sql = "UPDATE player
                                SET player_tire_tokens = player_tire_tokens -1
                                WHERE player_id = $id AND player_tire_tokens > 0";
                        self::DbQuery($sql);

                        $tireTokens -= 1;
                        $optString = ' performing a "side shift" (-1 TireToken)'; // in italian: 'scarto laterale'
                    } else $tireTokens = 0;

                    self::notifyAllPlayers('placeGearVector', clienttranslate('${player_name} placed the gear vector'.$optString), array(
                        'player_name' => self::getActivePlayerName(),
                        'player_id' => $id,
                        'x' => $x,
                        'y' => $y,
                        'direction' => $orientation,
                        'tireTokens' => $tireTokens,
                        'gear' => $gear
                    ));

                    $this->gamestate->nextState('endVectorPlacement');
                    return;
                }
            }

            throw new BgaVisibleSystemException('Invalid gear vector position');
        }
    }

    function breakCar() {
        if ($this->checkAction('breakCar')) {

            // check if player has indeed no valid positionts, regardless of which state he takes this action from (car or vector placement)
            $arg = user_func_call('self::'.$this->gamestate->state()['args']);
            if ($arg['hasValid']) throw new BgaUserException('You cannot perform this move if you already have valid positions');

            if ($this->gamestate->state()['name'] == 'carPlacement') {
                // if called during this state, a vector has already been places so it has to be removed from db
                $sql = "DELETE FROM game_element
                WHERE entity = 'gearVector'";
                self::DbQuery($sql);
            }            

            self::notifyAllPlayers('breakCar', clienttranslate('${player_name} had to break to avoid a collision'), array(
                'player_name' => self::getActivePlayerName()
            ));

            $this->gamestate->nextState('tryNewGearVector');
            return;
        }
    }

    function useBoost($use) {
        if ($this->checkAction('useBoost')) {

            if($use) {

                $id = self::getActivePlayerId();
<<<<<<< Updated upstream
                $nitroTokens = self::getPlayerTokens($id)['nitroTokens'];
=======
                $nitroTokens = self::getPlayerTokens($id)['nitro'];
>>>>>>> Stashed changes

                if ($nitroTokens == 0) throw new BgaUserException(self::_("You don't have enough Nitro Tokens to do use a boost"));

                $sql = "UPDATE player
                        SET player_nitro_tokens = player_nitro_tokens -1
                        WHERE player_id = $id AND player_nitro_tokens > 0";
                self::DbQuery($sql);

                $nitroTokens += -1;

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
                        'direction' => $direction,
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

            foreach ($allPos as $pos) {
                
                if ($pos['position'] == $position) {

                    $allDir = $pos['directions'];

                    foreach ($allDir as $dir) {
                        
                        if ($dir['direction'] == $direction) {

                            $id = self::getActivePlayerId();
                            
                            $tireTokens = self::getPlayerTokens($id)['tire'];

                            $optString = '';

                            if ($dir['black']) {

                                if ($tireTokens == 0) throw new BgaUserException(self::_("You don't have enough Tire Tokens to do this move"));
                                
                                $sql = "UPDATE player
                                        SET player_tire_tokens = player_tire_tokens -1
                                        WHERE player_id = $id AND player_tire_tokens > 0";
                                self::DbQuery($sql);

                                $tireTokens--;
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

                            $sql = "DELETE FROM game_element
                                    WHERE entity = 'gearVector' OR entity = 'boostVector'";
                            self::DbQuery($sql);

                            self::notifyAllPlayers('placeCar', clienttranslate('${player_name} placed their car'.$optString), array(
                                'player_name' => self::getActivePlayerName(),
                                'player_id' => $id,
                                'x' => $x,
                                'y' => $y,
                                'rotation' => $rotation,
                                'tireTokens' => $tireTokens,
                            ));

                            $this->gamestate->nextState('endMovement'); /* (self::availableAttackManeuvers())? 'attack' : */
                            return;
                        }
                    }

                    throw new BgaVisibleSystemException('Invalid car direction');

                }
            }

            throw new BgaVisibleSystemException('Invalid car position');
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
        
        $sql = "SELECT pos_x x, pos_y y, orientation dir
                FROM game_element
                WHERE entity = 'pitwall'";
        $pitwall = self::getObjectFromDb($sql);

        $pitwall = new VektoraceVector (new VektoracePoint($pitwall['x'], $pitwall['y']), $pitwall['dir'], 4);
        $anchorVertex = $pitwall->getBottomOct()->getVertices()[1];
        
        $windowCenter = clone $anchorVertex;

        $placementWindowSize = array('width' => VektoraceOctagon::getOctProperties()['size'], 'height' => VektoraceOctagon::getOctProperties()['size']*5);
        
        $ro = $placementWindowSize['width']/2;
        $omg = ($pitwall->getDirection()-4) * M_PI_4;
        $windowCenter->translate($ro*cos($omg), $ro*sin($omg));

        $ro = $placementWindowSize['height']/2;
        $omg = ($pitwall->getDirection()-2) * M_PI_4;
        $windowCenter->translate($ro*cos($omg), $ro*sin($omg));

        ///
        /* $allv = $pitwall->getBottomOct()->getVertices();
        foreach ($allv as $i => $v) {
            $allv[$i] = $v->coordinates();
        } */
        ///

        return array("anchorPos" => $anchorVertex->coordinates(), "rotation" => 4 - $pitwall->getDirection(), 'center' => $windowCenter->coordinates()/* , 'debug' => array('windowSize' => $placementWindowSize) */);
    }

    // returns coordinates and useful data for all available (valid and not) flying start positition for each possible reference car
    function argFlyingStartPositioning() {

        $activePlayerTurnPosition = self::getPlayerTurnPos(self::getActivePlayerId());
        
        $allpos = array();
        foreach (self::loadPlayersBasicInfos() as $id => $playerInfos) {
            
            if ($playerInfos['player_no'] < $activePlayerTurnPosition) // take only positions from cars in front
                $allpos[$id] = array(
                    'coordinates' => self::getPlayerCarOctagon($id)->getCenter()->coordinates(),
                    'positions' => self::getPlayerCarOctagon($id)->flyingStartPositions(),
                    'hasValid' => false
                );
        }

        // -- invalid pos check --
        // a position is invalid if:
        // - it intersect with the pitlane
        // - it intersect with a curve 
        // - it intersect with an already palced car
        // - it is not behind the car ahead in the turn order (in respect to the car's nose line).

        $playerBefore = self::getPlayerTurnPosNumber($activePlayerTurnPosition-1); 

        foreach ($allpos as &$refpos) { // for each reference car on the board
<<<<<<< Updated upstream

            $positions = array();
            foreach (array_values($refpos['positions']) as $pos) { // for each position of the reference car

                $playerCar = self::getPlayerCarOctagon($playerBefore); // construct octagon from ahead player's position
                $posOct = new VektoraceOctagon($pos); // construct octagon of current position

                $vertices = $posOct->getVertices();
                foreach ($vertices as &$v) {
                    $v = $v->coordinates();
                } unset($v);

=======

            $positions = array();
            foreach (array_values($refpos['positions']) as $pos) { // for each position of the reference car

                $playerCar = self::getPlayerCarOctagon($playerBefore); // construct octagon from ahead player's position
                $posOct = new VektoraceOctagon($pos); // construct octagon of current position

                $vertices = $posOct->getVertices();
                foreach ($vertices as &$v) {
                    $v = $v->coordinates();
                } unset($v);

>>>>>>> Stashed changes
                // if pos is not behind or a collision is detected, report it as invalid
                $positions[] = array(
                    'coordinates' => $pos->coordinates(),
                    'vertices' => $vertices,
                    'valid' => ($posOct->isBehind($playerCar) && !self::detectCollision($posOct))
                );
            }

            $refpos['positions'] = $positions;
<<<<<<< Updated upstream

            foreach ($positions as $pos) {
                if ($pos['valid']) {
                    $refpos['hasValid'] = true;
                    break;
                }
            }

        } unset($refpos);

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

    // returns coordinates and useful data to position vector adjacent to the player car
    function argGearVectorPlacement() {
        $playerCar = self::getPlayerCarOctagon(self::getActivePlayerId());
        $currentGear = self::getPlayerCurrentGear(self::getActivePlayerId());
=======

            foreach ($positions as $pos) {
                if ($pos['valid']) {
                    $refpos['hasValid'] = true;
                    break;
                }
            }

        } unset($refpos);

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

    // returns coordinates and useful data to position vector adjacent to the player car
    function argGearVectorPlacement($predictFromGear=null) {

        $id = self::getActivePlayerId();

        $playerCar = self::getPlayerCarOctagon($id);

        $currentGear = self::getPlayerCurrentGear($id);
        if (!is_null($predictFromGear)) $currentGear = $predictFromGear;

>>>>>>> Stashed changes
        $direction = $playerCar->getDirection();
        
        $positions = array();
        $posNames = array('left-side','left','front','right','right-side');

        // iter through all 5 adjacent octagon
        foreach (array_values($playerCar->getAdjacentOctagons(5)) as $i => $anchorPos) {

            // construct vector from that anchor position
            $vector = new VektoraceVector($anchorPos, $direction, $currentGear, 'bottom');

            // return vector center to make client easly display it, along with anchor pos for selection octagon, and special properties flag
            $positions[] = array(
                'position' => $posNames[$i],
                'anchorCoordinates' => $anchorPos->coordinates(),
                'vectorCoordinates' => $vector->getCenter()->coordinates(),
                'tireCost' => ($i == 0 || $i == 4), // pos 0 and 4 are left-side and right-side respectevly, as AdjacentOctagons() returns position in counter clockwise fashion
                'legal' => !self::detectCollision($vector,true)
            );
        }

        $hasValid = false;

        foreach ($positions as $pos) {
            if ($pos['legal'] && !($pos['tireCost'] && self::getPlayerTokens(self::getActivePlayerId())['tire']<1)) {
                $hasValid = true;
                break;
            }
        }

        return array('positions' => $positions, 'direction' => $direction, 'gear' => $currentGear, 'hasValid' => $hasValid);
    }

<<<<<<< Updated upstream
=======
    function argEmergencyBreak() {

        $playerCar = self::getObjectFromDb("SELECT pos_x x, pos_y y, orientation dir FROM game_element WHERE id = ".$id = self::getActivePlayerId());

        $carOct = new VektoraceOctagon(new VektoracePoint($playerCar['x'],$playerCar['y']), $playerCar['dir']);
        return array('directionArrows' => array_values($carOct->getAdjacentOctagons(3)));
    }

>>>>>>> Stashed changes
    // works similarly to method above, but returns adjacent octagons in a chain to get a number of octagon straight in front of each others
    function argBoostVectorPlacement() {

        $gearVec = self::getPlacedVector('gear');
        $gear = $gearVec->getLength();

        $next = $gearVec->getTopOct();
        $direction = $gearVec->getDirection();

        $positions = array();
        for ($i=0; $i<$gear-1; $i++) {

            $vecTop = array_values($next->getAdjacentOctagons(1))[0];
            $vector = new VektoraceVector($vecTop, $direction, $i+1, 'top');
            $next = new VektoraceOctagon($vecTop, $direction);

            $positions[] = array(
                'vecTopCoordinates' => $vecTop->coordinates(),
                'vecCenterCoordinates' => $vector->getCenter()->coordinates(),
                'length' => $i+1,
<<<<<<< Updated upstream
                'legal' => !self::detectCollision($vector,true)
=======
                'legal' => !self::detectCollision($vector,true) && self::argCarPlacement($vector, true)['hasValid']  // leagl/valid, as it also checks if this particular boost lenght produces at least one vaild position
>>>>>>> Stashed changes
            );
        }

        $hasValid = false;

        foreach ($positions as $pos) {
            if ($pos['legal']) {
                $hasValid = true;
                break;
            }
        }

        return array('positions' => $positions, 'direction' => $direction, 'hasValid' => $hasValid);
    }

    // works as every positioning arguments method, but also adds information about rotation arrows placements (treated simply as adjacent octagons) and handles restriction on car possible directions and positioning
<<<<<<< Updated upstream
    function argCarPlacement() {
=======
    function argCarPlacement($predictFromVector=null,$isPredBoost=false) {
>>>>>>> Stashed changes

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

        self::dump('// DUMP ARG CAR PLACEMENT TOP ANCHOR', $topAnchor);
        self::dump('// DUMP ARG CAR PLACEMENT VECTORO LENGHT', $n);
        self::dump('// DUMP ARG CAR PLACEMENT IS BOOST', $isBoost);

        $dir = $topAnchor->getDirection();

        $positions = array();
        $posNames = array('left-side','left','front','right','right-side');

        foreach (array_values($topAnchor->getAdjacentOctagons(5)) as $i => $carPos) {

            $carOct = new VektoraceOctagon($carPos, $dir);
            $directions = array();
            $dirNames = array('left', 'straight', 'right');

            foreach (array_values($carOct->getAdjacentOctagons(3)) as $j => $arrowPos) {
                
                if (!($i==0 && $j==2) || ($i==4 && $j==0))
                    $directions[] = array(
                        'direction' => $dirNames[$j],
                        'coordinates' => $arrowPos->coordinates(),
                        'rotation' => $j-1,
                        'black' => $i==0 || $i==4 || ($i==1 && $j==2) || ($i==3 && $j==0)
                    );
            }

            $positions[] = array(
                'position' => $posNames[$i],
                'coordinates' => $carPos->coordinates(),
                'directions' => $directions,
                'tireCost' => $i==0 || $i==4,
                'legal' => !self::detectCollision($carOct)
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

<<<<<<< Updated upstream
=======
        $hasValid = false;

>>>>>>> Stashed changes
        // always easier to return non associative arrays for lists of positions, so that js can easly iterate through them
        $positions = array_values($positions);
        foreach ($positions as $i => $pos) {
            $positions[$i]['directions'] = array_values($positions[$i]['directions']);

            self::dump('// TRACE PLAYER TOKEN DURING ARG CAR POS',self::getPlayerTokens(self::getActivePlayerId())['tire']);
            if ($pos['legal'] && !($pos['tireCost'] && self::getPlayerTokens(self::getActivePlayerId())['tire']<1)) $hasValid = true;
        }

        return array('positions' => $positions, 'direction' => $dir, 'hasValid' => $hasValid);
    }

    // TODO
    function argAttackManeuvers() {
        return array("opponent" => '');
    }

    // return current gear. TODO: handle special cases and restrictions
    function argFutureGearDeclaration() {
        return array('gear' => self::getPlayerCurrentGear(self::getActivePlayerId()));
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
        $next_player_id = self::getPlayerAfter($player_id);

        /* $this->giveExtraTime($next_player_id);
        $this->incStat(1, 'turns_number', $next_player_id);
        $this->incStat(1, 'turns_number'); */

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

<<<<<<< Updated upstream
=======
    function stEmergencyBreak() {
        $shiftedGear = self::getPlayerCurrentGear(self::getActivePlayerId()) -1;

        while ($shiftedGear > 1) {
            $args = self::argGearVectorPlacement($shiftedGear);
            if ($args['hasValid']) {
                $sql = "UPDATE player
                        SET player_current_gear = $shiftedGear
                        WHERE player_id = ".self::getActivePlayerId();
                self::DbQuery();

                // SHOULD ALSO PREVENT BLACK MOVES AND ATTACK MANEUVERS FOR NEXT PHASES

                $this->gamestate->nextState('gearVectorPlacement');
                return;
            }
            $shiftedGear += -1;
        }

        // if reaches 0 it enters state and can use this state arguments for 'nailing' action
    }

>>>>>>> Stashed changes
    // TODO
    function stAttackManeuvers() {
        $this->gamestate->nextState();
    }

    // TODO
    function stEndOfMovementSpecialEvents() {
        $this->gamestate->nextState();
    }

    // gives turn to next player for car movement or recalculates turn order if all player have moved their car
    function stNextPlayer() {
        $player_id = self::getActivePlayerId();
        $np_id = self::getPlayerAfterCustom($player_id);

        /* $this->giveExtraTime($next_player_id);
        $this->incStat(1, 'turns_number', $next_player_id);
        $this->incStat(1, 'turns_number'); */

        if (self::getPlayerTurnPos($np_id) == 1) {

            $isChanged = self::newTurnOrder();
            
            $optString = '';
            if ($isChanged) $optString = ' The turn order has changed.';

            $sql = "SELECT player_id, player_turn_position FROM player";
            $turnOrder = self::getCollectionFromDb($sql, true);

            $this->gamestate->changeActivePlayer(self::getPlayerTurnPosNumber(1));

            self::notifyAllPlayers('nextRoundTurnOrder', clienttranslate('A new game round begins.'.$optString), $turnOrder);

        } else {
            $this->gamestate->changeActivePlayer($np_id);
        }

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
