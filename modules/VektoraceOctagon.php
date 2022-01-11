<?php

require_once('VektoracePoint.php');

// classe used to handle all octagons operation and measurments
class VektoraceOctagon {
    // size (in pixels, to represent a more direct conversion for js) of the box that inscribes the octagon, orizontal diameter
    // implicitly defines the scale of all generated octagons
    private static $size = 100;

    // octagon center coordinates as VektoracePoint
    private $center;

    // octagon elememt orientation (where is it facing, es. the car) [positive integer between 0 and 7]
    private $direction;

    private $isCurve;

    public function __construct(VektoracePoint $center, $direction=4, $isCurve=false) {

        $this->center = clone $center;

        if ($direction<0 || $direction>7) throw new Exception("Invalid 'direction' argument. Value must be between 0 and 7", 1);       
        $this->direction = $direction;

        $this->isCurve = $isCurve;
    }

    public function __clone() {
        $this->center = clone $this->center;
    }

    public function __toString() {
        return '[center: '.$this->center.', direction: '.$this->direction.']';
    }

    public function getCenter() {
        return clone $this->center;
    }

    public function isCurve() {
        return $this->isCurve;
    }

    public function getDirection() {
        return $this->direction;
    }
    
    // returns all useful measures when dealing with octagons
    public static function getOctProperties() {
        $sidlen = self::$size / (1 + 2/sqrt(2)); // length of all equal sides of the octagon
        $cseg = $sidlen / sqrt(2); // half the length of the segment resulting from size - side. or the cathetus of the rectangular triangle built on the angle of the box which inscribes the octagon.
        $radius = sqrt(pow(self::$size/2,2) + pow($sidlen/2,2)); // radius of the circle that inscribes the octagon. or the distance between the center and its furthest apexes

        return array("size" => self::$size,
                     "side" => $sidlen,
                     "corner_segment" => $cseg, // couldn't find a better name
                     "radius" => $radius);
    }

    // returns a list containing the center points of the $amount adjacent octagons, symmetric to the facing direction 
    // direction order is the same used to describe the game elements orientation in the database (counter clockwise, as $dir * PI/4)

    // REDO USING VECTORS
    public function getAdjacentOctagons(int $amount, $inverseDir=false) {

        //
        //       *  2  * 
        //     5         1
        //   *             * 
        //   4      +      0  ->
        //   *             * 
        //     6         8
        //       *  7  *   
        //

        if ($amount<1 || $amount>8) {
            throw new Exception("Invalid amount argument, value must be between 1 and 8", 1);
        }

        // take direction, obtain key as a function of amount (shift so that direction is in the middle of the keys), mod the result to deal with the overflow of the clock
        $key = ($inverseDir)? (($this->direction - 4 + 8) % 8) : $this->direction; 
        $key -= floor(($amount-1)/2); // floor necessary only when key is not odd number (should not happen)
        $key += 8;

        $ret = array();

        // for amount times, extract one adjacent octagon center coordinates, put it in the returned array and repeat
        for ($i=0; $i < $amount; $i++) {

            $c = clone $this->center;
            $c->translateVec(self::$size, (($key+$i)%8) * M_PI/4);
            $ret[] = $c;
        }

        return (count($ret)==1)? $ret[0] : $ret;
    }

    // returns array of all vertices of $this octagon. if $isCurve is true, return vertices in the shape of a curve, pointing in $this->direction (shown below)
    public function getVertices() {
        // get all useful proprieties to calculate the position of all vertices
        $octMeasures = self::getOctProperties();

        // compose array of vertices in a orderly manner (key = (K-1)/2 of K * PI/8. inversely: K = key*2 + 1)
        //      2  *  1 
        //    *       * *
        //  3         6   0
        //  *      *      *
        //  4 * 5         7
        //    *         *
        //      5  *  6    
        //             

        $ret = array();
        for ($i=0; $i<8; $i++) { 
         
            $c = clone $this->center;
            $c->translateVec($octMeasures['radius'], (2*$i+1) * M_PI/8);
            $ret[$i] = $c;
        }

        if ($this->isCurve) {

            $ret[5] = clone $ret[4];
            $ret[6] = clone $ret[1];

            $ret[5]->translate($octMeasures['side'],0);
            $ret[6]->translate(0,-$octMeasures['side']);

            $ret = array_slice($ret, 1, 6);
        }

        // rotate all points to face vec dir
        $the = ($this->direction - (($this->isCurve)? 3 : 4)) * M_PI_4; // 3 and 4 are standard orientation for curve and octagon respectively (due to how curves and cars are oriented in the image sprites)
        foreach ($ret as &$p) {
            $p->changeRefPlane($this->center);
            $p->rotate($the);
            $p->translate($this->center->x(),$this->center->y());
        }
        unset($p);

        if ($this->isCurve) {
            $ro = $octMeasures['size']/2 - ($octMeasures['side']+$octMeasures['corner_segment'])/2;
            $ro *= sqrt(2); // actually need diagonal of displacement 'square'
            $the = $this->direction * M_PI_4;

            foreach ($ret as &$p) {
                $p->translateVec(-$ro,$the);
            } unset($p);
        }
        
        return $ret;
    }
    
    // returns true if $this and $oct collide (uses SAT algo)
    public function collidesWith(VektoraceOctagon $oct, $consider = 'whole', $err = 1) {
        if ($this->isCurve && $consider != 'whole') throw new Exception('Cannot detect collision for curve when parameter "consider" is not default "whole". $consider: '.$consider);

        // compute distance between octagons centers
        $distance = VektoracePoint::distance($this->center,$oct->center);

        if ($distance < $err*2) return true; // elements basically overlapping

        // if it's a simple octagon and the distance is less then the size of the octagon itself, collision is assured
        if (!$this->isCurve && $consider=='whole' && $distance < self::$size-($err*2)) return true;

        // run sat algo only if distance is less then the octagons radius, thus surrounding circles intersects. 
        if ($distance < 2*self::getOctProperties()['radius']) {

            $oct1 = $this->getVertices();
            if ($consider == 'nose') $oct1 = array($oct1[3], $oct1[4]);
            if ($consider == 'car') $oct1 = array($oct1[0], $oct1[3], $oct1[4], $oct1[7]);

            $oct2 = $oct->getVertices();

            return self::SATcollision($oct1,$oct2,$err);
            
        } else return false;
    }

    // method takes two arrays of VektoracePoint objects as sets of vertices of a polygon
    // and returns true if a separating axis exists between them in their standard plane of refernce
    // (to check other axis, rotate points and repeat)
    public static function findSeparatingAxis($poli1, $poli2, $err = 0) {
        
        // extract all x and y coordinates to find extremes
        $P1X = $P1Y = [];
        $P2X = $P2Y = [];

        foreach ($poli1 as $vertex) {
            $P1X[] = $vertex->x();
            $P1Y[] = $vertex->y();
        }

        foreach ($poli2 as $vertex) {
            $P2X[] = $vertex->x();
            $P2Y[] = $vertex->y();
        }
        
        // extract x-axis intervals
        $P1a = min($P1X)+$err; // add rounding errors (make intervals smaller by 1 at each end)
        $P1b = max($P1X)-$err;
        
        $P2a = min($P2X)+$err;
        $P2b = max($P2X)-$err;

        // if poly1 interval ends before beginning of poly2 interval OR poly1 interval begins after end of poly2 interval -> intervals don't overlap, a separating axis exists
        if ($P1b < $P2a || $P1a > $P2b) return true;

        // esle checl y-axis
        // extract y-axis intervals
        $P1a = min($P1Y)+$err; // add rounding errors
        $P1b = max($P1Y)-$err;
        
        $P2a = min($P2Y)+$err;
        $P2b = max($P2Y)-$err;

        // (as before, but for the y)
        return $P1b < $P2a || $P1a > $P2b; // if true intervals don't overlap -> separating axis exists | if false intervals overlap -> no separating axis has ben found on this plane
    }

    // method searches for separating axis on standard (0deg) and 45deg rotated plane
    // returns true (collision detected) if no separating axis is found in either planes.
    // returns false (no collision detected), otherwise
    public static function SATcollision($poli1,$poli2, $err = 1) {
        
        if (self::findSeparatingAxis($poli1, $poli2, $err)) return false;
            
        $the = M_PI_4; // angle of rotation

        foreach ($poli1 as &$v) {
            $v = clone $v; // bit weird, needed to not modify original polygon vertices
            $v->rotate($the);
        }
        unset($v);

        foreach ($poli2 as &$v) {
            $v = clone $v;
            $v->rotate($the);
        }
        unset($v);

        return !self::findSeparatingAxis($poli1, $poli2, $err);
    }

    // detects collition between $this octagon and a vector object (basically analize vector as three different shapes, two octagons and a simple rectangle)
    public function collidesWithVector(VektoraceVector $vector, $consider = 'whole', $err = 1) {
        if ($this->isCurve && $consider != 'whole') throw new Exception('Cannot detect collision for curve when parameter "consider" is not default "whole". $consider: '.$consider);

        // OCTAGON COLLIDES WITH EITHER THE TOP OR BOTTOM VECTOR'S OCTAGON

        if ($this->collidesWith($vector->getBottomOct(), $consider, $err) || $this->collidesWith($vector->getTopOct(), $consider, $err)) return true;

        // OCTAGON COLLIDES WITH THE VECTOR'S INNER RECTANGLE

        if ($vector->getLength() < 3) return false; // if vector is of lenght smaller than 3, it has no inner rectangle
        
        $vectorInnerRect = $vector->innerRectVertices();
        $thisOct = $this->getVertices();
        if ($consider == 'nose') $thisOct = array($thisOct[3], $thisOct[4]);
        if ($consider == 'car') $thisOct = array($thisOct[0], $thisOct[3], $thisOct[4], $thisOct[7]);

        return self::SATcollision($vectorInnerRect, $thisOct, $err);

        /* $the = M_PI_4;

        if (self::findSeparatingAxis($vectorInnerRect, $thisOct)) return false;

        foreach ($vectorInnerRect as &$vertex) {
            $vertex->rotate($the);
        }
        unset($vertex);

        foreach ($thisOct as &$vertex) {
            $vertex->rotate($the);
        }
        unset($vertex);

        return !self::findSeparatingAxis($vectorInnerRect, $thisOct); */
    }

    // returns norm VektoracePoint "mathematic" vector that points in the direction where car is pointing, along with its origin (useful for other methods) the midpoint of its front edge
    public function getDirectionNorm() {

        $octVs = $this->getVertices();

        // find midpoint between them from which the norm originates
        $m = VektoracePoint::midpoint($octVs[3], $octVs[4]);

        // calculate norm vector
        $n = VektoracePoint::displacementVector($m, $this->center);
        $n->invert();
        $n->normalize();

        return array( 'norm' => clone $n, 'origin' => $m); // origin is midpoint of front edge
    }

    // returns true if $this octagon is behing $oct, according to the line defined by the front-facing edge of $oct (towards its $direction)
    // the idea is to find the norm of this front-facing edge and see if the dot product with each vertex of $this octagon results in negative (thus together they form an angle greater than 90deg, which means the vertex is behind that edge)
    public function isBehind(VektoraceOctagon $oct) {

        ['norm'=>$n, 'origin'=>$m] = $oct->getDirectionNorm();

        $thisCar = $this->getVertices();
        $thisCar = array($thisCar[3],$thisCar[4],$thisCar[7],$thisCar[0]);

        //$ret = array('norm'=>$n->coordinates(), 'origin'=>$m->coordinates());

        // for each vertex of $this, find vector from m to the vertex and calculate dotproduct between them
        foreach ($thisCar as $vertex) {
            $v = VektoracePoint::displacementVector($m, $vertex);
            $v->normalize();

            //$ret[] = VektoracePoint::dot($n, $v);
            if (VektoracePoint::dot($n, $v) >= -0.005) return false; // consider some error
        }

        return /* $ret  */true;
    }

    // determines if $this car new positions is sufficent to overtake $other car which is (presumibly) in front of.
    // according to game rules:
    // if (this) car (and NOT its wider octaogn base), IS NOT behind the nose line of the other car
    // and the other car IS behind the nose line of this car
    // then the car overtakes the one in front.
    // otherwise, if one of the two condition is not verified, the car doesn't overtake the one in front and keeps its previous position.
    // it might sound confusing but if you look at how the isBehind method is implemented you can understand how one car might be simultaneously in front and of and behind another car.
    public function overtake(VektoraceOctagon $other) {

        return !$this->isBehind($other) && $other->isBehind($this);
    }

    public function curveProgress(VektoraceOctagon $posOct) {

        if (!$this->isCurve) throw new Exception("Object should be a curve");

        // check in which zone center of pos lands
        $posCenter = $posOct->getCenter();

        $posVec = VektoracePoint::displacementVector($this->center, $posCenter);
        $posVec->normalize();

        for ($i=0; $i<8; $i++) {

            $the = (($this->direction - 4 - 0.5 - $i ) * M_PI_4);
            $zoneVec = new VektoracePoint();
            $zoneVec->translateVec(1,$the);

            /* echo(VektoracePoint::dot($posVec, $zoneVec));
            echo('//'); */

            if (VektoracePoint::dot($posVec, $zoneVec) >= cos(M_PI/8)) return $i;

        }

        // throw new Exception("Method shouldn't have reached this point");
    }

    public function inPitZone(VektoraceVector $pw, $zone, $checkVertices = 'nose') {

        $dir = $pw->getDirection();

        $O = $pw->getCenter();

        $topOct = $pw->getTopOct();
        $botOct = $pw->getBottomOct();

        $top = $topOct->getCenter();
        $bot = $botOct->getCenter();

        // find Q and P (translated points of top and bot to match pitbox entrance and exit)
        $ro = self::getOctProperties()['side']/2;
        $the = $dir * M_PI_4;

        $Q = $top;
        $Q->translateVec($ro, $the);

        $P = $bot;
        $P->translateVec($ro, $the-M_PI);

        $Q->changeRefPlane($O);
        $Q->scale(0.75,0.75);
        $Q->translate($O->x(),$O->y());

        $P->changeRefPlane($O);
        $P->scale(0.75,0.75);
        $P->translate($O->x(),$O->y());

        // norm vector pointing upward in respect to a layed down pitwall (dir 4)
        $a = clone $O;
        $a->translateVec(1, ($dir-2) * M_PI_4);

        // norm vector pointing opposite of pw dir
        $b = clone $O;
        $b->translateVec(1, ($dir-4) * M_PI_4);

        // norm vector pointing same as pw dir
        $c = clone $O;
        $c->translateVec(1, $dir * M_PI_4);

        $vertices = $this->getVertices();
        if ($checkVertices == 'nose') $vertices = [VektoracePoint::midpoint($vertices[3],$vertices[4])];
  
        $inside = 0;
        foreach ($vertices as $v) {

            $A = VektoracePoint::dot(
                $a,
                VektoracePoint::displacementVector($O, $v)
            ) > 0;

            $B = VektoracePoint::dot(
                $b,
                VektoracePoint::displacementVector($P, $v)
            ) > 0;

            $C = VektoracePoint::dot(
                $c,
                VektoracePoint::displacementVector($Q, $v)
            ) > 0;

            switch ($zone) {
                case 'grid': if ($A && !$B && !$C) $inside++;
                    break;
                
                case 'EoC': if ($A && $B) $inside++;
                    break;

                case 'entrance': if (!$A && $B) $inside++;
                    break;
                
                case 'box': if (!$A && !$B && !$C) $inside++;
                    break;
            
                case 'exit': if (!$A && $C) $inside++;
                    break;
                
                case 'SoC': if ($A && $C) $inside++;
                    break;
            }
        }

        switch ($checkVertices) {
            case 'nose':
                return $inside == 1;
                break;
            
            case 'whole':
                return $inside == 8;
                break;

            case 'any':
                return $inside > 0;
                break;
        }
    }

    function boxOvershootPenality($pw, $getDef = false) {

        // same stuff as method above
        // GONNA FIND A COMMON METHOD TO GET THIS
        $dir = $pw->getDirection();

        $O = $pw->getCenter();

        $topOct = $pw->getTopOct();
        $top = $topOct->getCenter();

        // find Q and P (translated points of top and bot to match pitbox entrance and exit)
        $ro = self::getOctProperties()['side']/2;
        $the = $dir * M_PI_4;

        $Q = $top;
        $Q->translateVec($ro, $the);

        $Q->changeRefPlane($O);
        $Q->scale(0.75,0.75);
        $Q->translate($O->x(),$O->y());

        // norm vector pointing same as pw dir
        $c = clone $O;
        $c->translateVec(1, $dir * M_PI_4);

        if ($getDef) {
            $newPos = clone $Q;
            $newPos->translateVec(self::getOctProperties()['size'], ($dir+2) * M_PI_4);
            $newPos->translateVec((self::getOctProperties()['size']/2)+1, $dir * M_PI_4 + M_PI);

            return $newPos;

        } else {
            // calc overshoot using distance to vector plane formula (same as old method to find distance to lightsource)
            $v = $this->getCenter();

            $c_dot_v = VektoracePoint::dot(
                VektoracePoint::displacementVector($Q, $c),
                VektoracePoint::displacementVector($Q, $v)
            );
            $mag_v = VektoracePoint::distance($Q, $v);

            $overshoot = ($c_dot_v / $mag_v) + self::getOctProperties()['size']/2 +1;

            $newPos = $this->getCenter();
            $newPos->translateVec($overshoot, $dir * M_PI_4 + M_PI);

            return $newPos;
        }
    }
}