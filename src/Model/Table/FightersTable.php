<?php

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

class FightersTable extends Table {
    /* Fonctions pour la validation des données */

    public function beforeSave($event, $entity) {
        if ($entity->isNew()) {
            $entity->coordinate_x = 5;
            $entity->coordinate_y = 5;
            $entity->skill_sight = 2;
            $entity->skill_strength = 1;
            $entity->skill_health = 5;
            $entity->current_health = 5;
            $entity->level = 0;
            $entity->xp = 0;
            $entity->next_action_time = date('Y-m-d H:i:s');
            $entity->guild_id = NULL;
        }
    }

    /* Fonctions pour trouver des combattants */

    public function getFighters() {
        $yo = $this->find('all')->toArray();
        return $yo;
    }

    public function getBestFighter() {
        return $this->find('all')->order('level')->first();
    }

    public function getFightersByPlayer($playerId) {
        $fighters = $this
                ->find()
                ->where(['player_id' => $playerId]);

        return $fighters;
    }

    public function getFighterById($id) {
        $yo = $this->find()->where(['id' => $id])->first();
        return $yo;
    }

    public function getFighterByCo($x, $y) {
        $yo = $this->find()->where(['coordinate_x' => $x, 'coordinate_y' => $y])->first();
        return $yo;
    }

    /* Fonctions pour gérer les combattants */

    public function kill($fighter) {

        //Création d'un évènement
        $eventsTables = TableRegistry::get('Events');
        $eventsTables->createEvent($fighter->name . ' est mort.', $fighter->coordinate_x, $fighter->coordinate_y);

        //On supprime le combattant
        $result = $this->delete($fighter);
    }

    public function winXp($fighter, $amount) {
        $fighter->xp += $amount;
        $this->save($fighter);
    }

    /* Fonctions pour vérifier les coordonnées */

    public function checkAdjacentCoordinates($coord1X, $coord1Y, $coord2X, $coord2Y) {
        if (abs($coord1X - $coord2X) + abs($coord1Y - $coord2Y) < 2) {
            return true;
        } else {
            return false;
        }
    }

    public function checkInViewCoordinates($fighter, $coordX, $coordY) {

        //On vérifie si les coordonnées sont à portée de vue du combattant
        $toolsTable = TableRegistry::get('Tools');
        $distance = $fighter->skill_sight + $toolsTable->getBonus($fighter->id, 'V');

        if (abs($coordX - ($fighter->coordinate_x)) + abs($coordY - ($fighter->coordinate_y)) <= $distance) {
            return true;
        } else {
            return false;
        }
    }

    /* Fonction d'actions */

    public function move($dir, $fighter) {

        //On charge les modèles
        $toolsTable = TableRegistry::get('Tools');
        $surroundingsTable = TableRegistry::get('Surroundings');
        $eventsTables = TableRegistry::get('Events');

        //On calcule la case sur laquelle va atterrir le combattant
        $dirToCo = $this->dirToCo($dir);
        $nextPos = array('x' => $fighter->coordinate_x + $dirToCo["x"], 'y' => $fighter->coordinate_y + $dirToCo["y"]);
        
        //On vérifie si le déplacement est possible
        if ($this->moveIsPossible($nextPos)) {
            $this->doMove($fighter, $nextPos);

            //On vérifie s'il y a un objet à prendre
            if ($this->toolIsThere($nextPos)) {
                $toolsTable->takeTool($nextPos["x"], $nextPos["y"], $fighter);
            }

            //On vérifie s'il y a un décor
            else if ($this->surroundingIsThere($nextPos)) {

                switch ($surroundingsTable->getSurroundingByCo($nextPos["x"], $nextPos["y"])) {

                    //Monstre
                    case "W":
                        //Création d'un évènement
                        $eventsTables->createEvent('Un monstre a dévoré ' . $fighter->name, $nextPos["x"], $nextPos["y"]);

                        //Le joueur est mort
                        $this->kill($fighter);
                        break;

                    //Trou
                    case "T":
                        //Création d'un évènement
                        $eventsTables->createEvent('Un trou a aspiré ' . $fighter->name, $nextPos["x"], $nextPos["y"]);

                        //Le joueur est mort
                        $this->kill($$fighter);
                        break;
                    
                    default:
                        break;
                }
            }
        }
    }
    
    public function doMove($fighter, $nextPos) {
        /* $fightersTable = TableRegistry::get('Fighters');
          $fighter = $fightersTable->get($fighterid);//ici on utilise fighterid en tant que clé */
        $fighter->coordinate_x = $nextPos["x"];
        $fighter->coordinate_y = $nextPos["y"];
        $this->save($fighter);
        //Création d'un évènement
        $eventsTables = TableRegistry::get('Events');
        $eventsTables->createEvent($fighter->name . ' avance.', $nextPos["x"], $nextPos["y"]);
    }

    public function attack($dir, $currentfighter) {
        
        //On charge les modèles
        $toolsTable = TableRegistry::get('Tools');
        $eventsTables = TableRegistry::get('Events');

        //On calcule la case à attaquer
        $dirToCo = $this->dirToCo($dir);
        $attackSpot = array('x' => $currentfighter->coordinate_x + $dirToCo["x"], 'y' => $currentfighter->coordinate_y + $dirToCo["y"]);
        
        //On vérifie s'il y a un combattant sur cette case
        if ($this->fighterIsThere($attackSpot)) {

            //On récupère le combattant ennemi
            $oponent = $this->getFighterByCo($attackSpot['x'], $attackSpot['y']);

            //On calcule la force de notre combattant
            $mystrength = $currentfighter->skill_strength + $toolsTable->getBonus($currentfighter->id, 'D');

            //Test si l'attaque réussit ou non
            if ($this->tryAttack($oponent, $currentfighter)) {

                //Le combattant ennemi est blessé
                $this->touchedByAttack($oponent, $currentfighter, $mystrength);

                //Création d'un évènement
                $eventsTables->createEvent($currentfighter->name . ' attaque et touche ' . $oponent->name, $attackSpot['x'], $attackSpot['y']);
            } else {
                //Création d'un évènement
                $eventsTables->createEvent($currentfighter->name . ' attaque et rate ' . $oponent->name, $attackSpot['x'], $attackSpot['y']);
            }
        } else {
            //Création d'un évènement
            $eventsTables->createEvent($currentfighter->name . ' se bat contre le vent et espère gagner... ', $attackSpot['x'], $attackSpot['y']);
        }
    }

    public function touchedByAttack($defender, $attacker, $strength) {

        //L'attaquant gagne de l'xp
       

        //On retire la force de l'attaque à la vie de l'ennemi
        if ($defender->current_health > $strength) {
            $defender->current_health -= $strength;
            $this->save($defender);
            $this->winXp($attacker, 1); //l'attaquant gagne 1 xp car le coup a reussit
        }

        //S'il n'as pas assez de vie, l'ennemi est mort
        else {
            $this->winXp($attacker, $defender->level); //l'attaquant gagne autant d'xp que le level du defender
            $this->kill($defender);
        }
    }

    /* Fonctions utilitaires */

    public function dirToCo($dir) {
        $dirToCo = array();
        switch ($dir) {
            case 'up':
                $dirToCo = array("x" => 0, "y" => -1);
                break;
            case 'down':
                $dirToCo = array("x" => 0, "y" => 1);
                break;
            case 'left':
                $dirToCo = array("x" => -1, "y" => 0);
                break;
            case 'right':
                $dirToCo = array("x" => 1, "y" => 0);
                break;
        }
        return $dirToCo;
    }

    public function exist($x, $y) {
        if (empty($this->find()->where(['coordinate_x' => $x, 'coordinate_y' => $y])->toArray())) {
            $exist = false;
        } else {
            $exist = true;
        }
        return $exist;
    }

    public function moveIsPossible($nextPos) {

        $surroundingsTable = TableRegistry::get('Surroundings');

        //Si le joueur est dans l'arène
        if ($nextPos["x"] >= 0 && $nextPos["x"] <= 14 && $nextPos["y"] >= 0 && $nextPos["y"] <= 9) {

            //Si la case ne contient pas un autre combattant ou une colonne
            if (!$this->exist($nextPos["x"], $nextPos["y"]) && !($surroundingsTable->getSurroundingByCo($nextPos["x"], $nextPos["y"])) == 'P') {
                return true;
            }
        }
        return false;
    }

    public function toolIsThere($nextPos) {

        $toolsTable = TableRegistry::get('Tools');

        //Si un objet est présent aux coordonnées données
        if ($toolsTable->exist($nextPos["x"], $nextPos["y"])) {
            return true;
        }
        return false;
    }

    public function surroundingIsThere($nextPos) {

        $surroundingsTable = TableRegistry::get('Surroundings');

        //Si un décor est présent aux coordonnées données
        if ($surroundingsTable->exist($nextPos["x"], $nextPos["y"])) {
            return true;
        }
        return false;
    }

    public function fighterIsThere($nextPos) {

        //Si un combattant est présent aux coordonnées données
        if ($this->exist($nextPos['x'], $nextPos['x'])) {
            return true;
        }
        return false;
    }

    public function getEventByFighter($playerId, $events) {

        //Déclaration des variables
        $found_events = array();
        $i = 0;

        //On récupère tous les personnages du joueur
        $fighters = $this->getFightersByPlayer($playerId);

        //On vérifie si les évènements sont à portée de vue du combattant
        foreach ($events as $event):
            foreach ($fighters as $fighter):
                if ($this->checkInViewCoordinates($fighter, $event->coordinate_x, $event->coordinate_y)) {
                    if (!in_array($event, $found_events)) {
                        $found_events[$i] = $event;
                        $i++;
                    }
                }
            endforeach;
        endforeach;

        return $found_events;
    }

    public function tryAttack($oponent, $currentfighter) {
        if ((rand(1, 20) > (10 + $oponent->level - $currentfighter->level))) {
            return true;
        }
        return false;
    }

    public function createViewTab($currentfighter) {

        //On charge les différents modèles
        $toolsTable = TableRegistry::get('Tools');
        $surroundingsTable = TableRegistry::get('Surroundings');

        //On créée les listes à partir de ces modèles
        $fighterlist = $this->getFighters();
        $toollist = $toolsTable->getTools();
        $surroundinglist = $surroundingsTable->getSurroundings();

        //Autres variables
        $viewtab = array(array());

        //On vérifie les cases à portée de vue du joueur
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 15; $x++) {
                if ($this->checkInViewCoordinates($currentfighter, $x, $y)) {
                    $unused = true;

                    //On affiche les combattants
                    if ($unused) {
                        foreach ($fighterlist as $fighter) {
                            if ($fighter->coordinate_x == $x && $fighter->coordinate_y == $y) {
                                $viewtab[$x][$y] = "rogue"; // ici on devrait mettre le nom du skin du fighter en question------------------------------
                                $unused = false;
                            }
                        }
                    }

                    //On affiche les objets
                    if ($unused) {
                        foreach ($toollist as $tool) {
                            if ($tool->coordinate_x == $x && $tool->coordinate_y == $y) {
                                switch ($tool->type){
                                    case "V":
                                        $viewtab[$x][$y] = "jumelle";
                                        break;
                                    case "D":
                                        $viewtab[$x][$y] = "epee";
                                        break;
                                    case "L":
                                        $viewtab[$x][$y] = "armure";
                                        break;
                                }
                                $unused = false;
                            }
                        }
                    }

                    //On affiche les décors
                    if ($unused) {
                        foreach ($surroundinglist as $surrounding) {
                            if ($surrounding->coordinate_x == $x && $surrounding->coordinate_y == $y) {
                                if ($surrounding->type == "P"){ //on ne traite pas les cas des monstres et trous qui sont invisibles
                                    $viewtab[$x][$y] = "colonne";
                                    $unused = false;
                                }
                            }
                        }
                    }

                    //On affiche l'herbe
                    if ($unused) {
                        $viewtab[$x][$y] = 'herbe';
                    }
                }
            }
        }
        return $viewtab;
    }
    
    public function getSelectedFighter(){
        return 3;
    }
}
