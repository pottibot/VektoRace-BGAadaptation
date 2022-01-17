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
 
$machinestates = array(

    // INITIAL STATE (DO NOT MODIFY)
    1 => array(
        "name" => "gameSetup",
        "description" => "",
        "type" => "manager",
        "action" => "stGameSetup",
        "transitions" => array( "" => 2 )
    ),

    // FIRST PLAYER FLYING START POSITIONING
    // the first player decides where to place its car along the pitlane entrance line (to the track side)
    2 => array(
        "name" => "firstPlayerPositioning",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must choose their starting position'),
        "descriptionmyturn" => clienttranslate('${you} must choose your starting position'),
        "possibleactions" => array( "placeFirstCar" ),
        "args" => "argFirstPlayerPositioning",
        "transitions" => array( "" => 4) // after initial positioning take tokens
    ),

    // PLAYER POSITIONING (should be called SELECT START POSITION)
    // "flying-start" initial positioning phase.
    // each player places it's F8 racing car behind the car in front, using a special octagonal the valid positions.
    3 => array(
        "name" => "flyingStartPositioning",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must choose their starting position'),
        "descriptionmyturn" => clienttranslate('${you} have to select a reference car to determine all possible "flying-start" positions'),
        "possibleactions" => array( "placeCarFS" ),
        "args" => "argFlyingStartPositioning", 
        "transitions" => array( "" => 4) // same as above
    ),

    // TOKEN TYPE AMMOUNT CHOICE
    // each player chooses how many tokens of each type to take, with a maximum of 8 total
    4 => array(
        "name" => "tokenAmountChoice",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must choose with how many token of each type they wish to start the game'),
        "descriptionmyturn" => clienttranslate('${you} must choose with how many token of each type you wish to start the game'),
        "possibleactions" => array( "chooseTokensAmount" ),
        "args" => "argTokenAmountChoice", 
        "transitions" => array( "" => 5) // player positioning phase has ended, gives turn to next player
    ),

    // NEXT POSITIONING [CONTROL]
    // game checks if all players have placed their car, if not, activates next player in standard turn order and jumps to flyingStartPositioning.
    // otherwise, activates first player and jumps to greenLight state
    5 => array(
        "name" => "nextPositioning",
        "type" => "game",
        "description" => "",
        "action" => "stNextPositioning",
        "transitions" => array( "nextPositioningPlayer" => 3, "gameStart" => 6) // if positioning phase completed, go to 'green light' phase, otherwise repeat 'playerPositioning' state
    ),

    // GREEN LIGHT
    // the first player chooses the starting gear for every player
    6 => array(
        "name" => "greenLight",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must choose the starting gear vector for all players'),
        "descriptionmyturn" => clienttranslate('${you} must choose the starting gear vector for all players'),
        "args" => "argGreenLight",
        "possibleactions" => array( "chooseStartingGear" ),
        "transitions" => array( "" => 7)
    ),

    // PLACE VECTOR
    // the active player chooses where to place its gear vector.
    7 => array(
        "name" => "gearVectorPlacement",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must place their current gear vector'),
        "descriptionmyturn" => clienttranslate('${you} must place your current gear vector'),
        "args" => "argGearVectorPlacement",
        "possibleactions" => array("placeGearVector", "brakeCar", "giveWay"),
        "transitions" => array("boostPromt" => 8, "skipBoost" => 10, "slowdownOrBrake" => 17, "setNewTurnOrder" => 19),
        "updateGameProgression" => true
    ),

    // BOOST PROMT
    // after gear placement and before car placement, the plyer might choose to extend his movement by using a boost vector, which costs 1 nitro token
    8 => array(
        "name" => "boostPrompt",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} can choose to use a boost to extend their car movement'),
        "descriptionmyturn" => clienttranslate('${you} can choose to use a boost to extend your car movement'),
        "possibleactions" => array("useBoost"),
        "transitions" => array("use" => 9, "skip" => 10)
    ),

    // USE BOOST
    // if player chose to use the boost vector, he might freely decide which lenght to utilize
    9 => array(
        "name" => "boostVectorPlacement",
        "type" => "activeplayer",
        "action" => "stBoostVectorPlacement",
        "description" => clienttranslate('${actplayer} must choose which boost they want to use'),
        "descriptionmyturn" => clienttranslate('${you} must choose which boost you want to use'),
        "args" => "argBoostVectorPlacement",
        "possibleactions" => array("placeBoostVector"),
        "transitions" => array("" => 10)
    ),

    // PLACE CAR
    // the player decides where to place its car on top of the placed vector and which way should it be pointing
    10 => array(
        "name" => "carPlacement",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must choose where they want to place their car'),
        "descriptionmyturn" => clienttranslate('${you} must choose where you want to place your car'),
        "args" => "argCarPlacement",
        "possibleactions" => array("placeCar"),
        "transitions" => array("attack" => 12, "boxEntrance" => 11, "endMovement" => 14)
    ),

    // PIT STOP
    // the player decides where to place its car on top of the placed vector and which way should it be pointing
    11 => array(
        "name" => "pitStop",
        "type" => "activeplayer",
        "action" => "stPitStop",
        "description" => clienttranslate('${actplayer} must choose how to refill their token reserve'),
        "descriptionmyturn" => clienttranslate('${you} must choose how to refill your token reserve'),
        "args" => "argPitStop",
        "possibleactions" => array("chooseTokensAmount"),
        "transitions" => array("" => 14)
    ),

    // ATTACK MANEUVERS
    // at the end of the movement phase, the player can choose (if possible) to engage in special attack maneuvers to sabotage, flank or surpass an opponent
    12 => array(
        "name" => "attackManeuvers",
        "type" => "activeplayer",
        "action" => "stAttackManeuvers",
        "description" => clienttranslate('${actplayer} can engage in some attack maneuvers'),
        "descriptionmyturn" => clienttranslate('${you} can choose to attack a car'),
        "args" => "argAttackManeuvers", 
        "possibleactions" => array("engageManeuver","skipAttack"),
        "transitions" => array( "noManeuver" => 14, "completeManeuver" => 14) /* ??? */
    ),

    // BOXBOX PROMT
    // a player which enters the pit stop area can collect tire and nitro tokens (minus eventual penalities)
    13 => array(
        "name" => "boxBoxPromt",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} can decide to call "BoxBox!"'),
        "descriptionmyturn" => clienttranslate('${you} can decide to call '),
        "possibleactions" => array( "boxBox" ),
        "transitions" => array( "" => 15)
    ),   

    // END OF MOVEMENT SPECIAL EVENETS [CONTROL]
    // game checks for special events that triggers at the end of a player's movement, such as victory condition and pit-stop entrance. 
    14 => array(
        "name" => "endOfMovementSpecialEvents",
        "type" => "game",
        "action" => "stEndOfMovementSpecialEvents",
        "transitions" => array( "gearDeclaration" => 15, "skipGearDeclaration" => 16, "boxBox" => 13/* , "raceEnd" => 99 */)
    ),

    // FUTURE GEAR DECLARATION
    // before finishing his turn, the active player must declare what gear he will be using for his next turn.
    // he might only shift the current gear up or down by one (plus other modifiers such as penalities from a suffered attack maneuvers or spending tokens to shift by more than one gear)
    15 => array(
        "name" => "futureGearDeclaration",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must declare what gear they will use in the next turn'),
        "descriptionmyturn" => clienttranslate('${you} must declare what gear you will use in the next turn'),
        "args" => "argFutureGearDeclaration",
        "possibleactions" => array("declareGear"),
        "transitions" => array("" => 16)
    ),

    // NEXT PLAYER TURN [CONTROL]
    // game checks if all player have completed their movement, if so, it produces a new turn order based on the current car standings position; otherwise, it gives control to the next player in the previously determined turn order.
    // BEFORE GIVING CONTROL THE THE NEXT PLAYER, STATE SHOULD ALSO CHECK FOR PENALITIES IF THE PLAYER CANNOT MAKE ANY VALID MOVE WITH HIS CURRENT GEAR VECTOR AND JUMP STATE ACCORDINGLY (either for penalities or give way state)
    16 => array(
        "name" => "nextPlayer",
        "type" => "game",
        "action" => "stNextPlayer",
        "transitions" => array( "" => 7)
    ),

    // SLOWDOWN OR BRAKE [CONTROL]
    17 => array(
        "name" => "EmergencySlowdownOrBrake",
        "type" => "game",
        "action" => "stEmergencySlowdownOrBrake",
        "transitions" => array("slowdown" => 7, "brake" => 18,)
    ),

    // EMERGENCY BREAK
    // game first checks if lower vector lenghs produce valid positions, if so, it updates db with new current gear and jumps cback to moveement phase
    18 => array(
        "name" => "emergencyBrake",
        "type" => "activeplayer",
        "description" => clienttranslate('${actplayer} must choose how to rotate their car'),
        "descriptionmyturn" => clienttranslate('${you} must choose how to rotate your car'),
        "args" => "argEmergencyBrake",
        "possibleactions" => array("rotateAfterBrake"),
        "transitions" => array("" => 16)
    ),

    // GIVE WAY (CEDERE IL PASSO)
    // a player with an obstructing car in front (but whom's turn order is behind) might decide to yield his turn to that player so to have more space to moove in during his next movement (happens rarely, during sharp turns)
    19 => array(
        "name" => "giveWay",
        "type" => "game",
        "action" => "stGiveWay",
        "transitions" => array( "" => 7) // should calculates new play order and finally start next turn
    ),
    
    // FINAL STATE (DO NOT MODIFY)
    99 => array(
        "name" => "gameEnd",
        "description" => clienttranslate("End of game"),
        "type" => "manager",
        "action" => "stGameEnd",
        "args" => "argGameEnd"
    )

);



