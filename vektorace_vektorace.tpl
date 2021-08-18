{OVERALL_GAME_HEADER}

<!-- 
--------
-- BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
-- VektoRace implementation : © <Pietro Luigi Porcedda> <pietro.l.porcedda>
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-------
-->

<!-- SCROLLABLE MAP DIV -->
<div id="map_container">
    <div id="map_scrollable">
        <div id="track">
            <div id="bggrid"></div>
        </div>
    </div>
    <div id="map_surface"></div>
    <div id="map_scrollable_oversurface">
        <div id="touchable_track">
            <div id="pos_highlights"></div>
            <div id="car_highlights"></div>
        </div>
    </div>
    
    <!-- arrows -->
    <div class="movetop"></div> 
	<div class="movedown"></div> 
	<div class="moveleft"></div> 
	<div class="moveright"></div> 
</div>

<div></div>


<script type="text/javascript">

// JAVASCRIPT TEMPLATES

// -- table elements --
var jstpl_pitwall = "<div id='pitwall'></div>";
// WOULD HAVE LIKED TO PUT THIS -> style='transform: rotate(${deg}deg) scale(${scale}) 
// INSIDE TO MAKE IT MORE DESDCRIPTIVE, BUT BGAFRAMEWORK POSITIONING SYSTEM WITH DOJO STUFF IS CONFLICTING WITH CSS PURE TRANSFORM
// SAME GOES FOR CURVES AND CARS
var jstpl_curve = "<div class='curve' id='curve_${n}'></div>";

var jstpl_car = "<div class='car' id='car_${color}'></div>";


// -- abstract elements -- 
var jstpl_posArea = "<div id='start_positioning_area'></div>";

var jstpl_selOctagon = "<div class='selectionOctagon' id='selOct_${x}_${y}'></div>"

</script>  

{OVERALL_GAME_FOOTER}