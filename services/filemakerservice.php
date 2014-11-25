<?php
// filemakerBundle/services/filemakerservice.php

namespace filemakerBundle\services;

use Symfony\Component\DependencyInjection\ContainerInterface;

class filemakerservice {

	protected $data = array();
	protected $container;				// ContainerInterface
	protected $APIfm;					// objet FileMaker (accès user logged)
	protected $APIfmSA;					// objet FileMaker (accès Super Admin)
	protected $FMfind;					// Résultat de recherche FM

	protected $APIfm_paramfile;			// fichier de paramètres de l'API FileMaker

	// SAdmin
	protected $dbname = null;			// nom de la base
	protected $dbuserSA;				// login du Super Admin
	protected $dbpassSA;				// mot de passe du Super Admin
	protected $dbdescribe;				// description de la bd
	protected $SA_defined 	= false;	// Super Admin trouvé ou non (boolean)
	protected $SA_logged 	= false;	// Super Admin connecté ou non (boolean)
	// User
	protected $username;				// username
	protected $loguser;					// login user
	protected $logpass;					// pass user
	protected $user_defined = false;	// user trouvé ou non (boolean)
	protected $user_logged 	= false;	// user connecté ou non (boolean)

	// Données globales
	protected $listOfDatabases;			// liste des bases de données
	protected $listScripts = array(); 	// liste des scripts par BDD
	protected $listOfModels = array(); 	// liste des modèles par BDD

	protected $errors = array();		// messages d'erreur

	// ATTENTION :
	// AJOUTER le namespace dans l'API FileMaker : namespace filemakerBundle\services;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
		$this->APIfm_paramfile = __DIR__."/../../../../../app/config/parameters_fm.xml";
		require_once(__DIR__."/../FM/FileMaker.php");
		// paramètres logg + logge SAdmin
		if($this->param_findSadmin() === true) {
			// Liste des BDD
			$this->listOfDatabases = $this->getDatabasesSAdmin();
			// Liste des scripts par BDD
			foreach ($this->listOfDatabases as $key => $BDD) {
				$this->listScripts[$BDD] = $this->getScritpsByBddSAdmin($BDD);
			}
			// Liste des modèles par BDD
			foreach ($this->listOfDatabases as $key => $BDD) {
				$this->listOfModels[$BDD] = $this->getLayoutsByBddSAdmin($BDD);
			}
		} else {
			$this->addError('Connexion FM impossible.');
		}
		// liste des BDD
		return $this;
	}

	public function __destruct() {
		// $this->affErrors();
	}

	protected function getRecords($result) {
		if ($this->APIfm->isError($result)) {
		    $records = "Accès non autorisé.";
		} else {
			$records = $result->getRecords();
		}
		return $records;
	}

	/**
	 * Trouve les login et passe du sadmin
	 * dans le fichier app/config/parameters_fm.xml
	 * + logge SAdmin
	 * @param $file / chemin et nom du fichier xml
	 * @param $superadmin / permet de changer le username recherché ('sadmin' par défaut)
	 * @return boolean (true si succès)
	 */
	protected function param_findSadmin($file = null, $superadmin = 'default') {
		$this->setSadminDefined(true);
		$checkServer = array(
			"dbname" 		=> "nom",
			"dbdescribe" 	=> "descriptif",
			);
		$checkSA = array(
			"username" 		=> "username",
			"dbuserSA" 		=> "login",
			"dbpassSA" 		=> "passe",
			);
		if($file === null) $file = $this->APIfm_paramfile;
		if(file_exists($file)) {
			$xmldata = simplexml_load_file($file);
			// echo("<pre>");var_dump($xmldata);echo("</pre>");
			// BASE DE DONNÉES
			$findServ = $xmldata->xpath("/FMSERVERS/FMBASE[@default='default']");
			if(count($findServ) > 0) {
				reset($findServ);
				$attr = current($findServ)->attributes();
				foreach($checkServer as $nomvar => $champ) {
					if(isset($attr[$champ])) {
						$this->$nomvar = $attr[$champ];
					} else {
						foreach($checkServer as $nomvar2 => $champ2) {
							$this->$nomvar2 = null;
						}
						$this->addError('Données de serveur (accès) absentes.');
						$this->setSadminDefined(false);
						break(2);
					}
				}
			} else {
				$this->addError('Données de serveur absentes.');
				$this->setSadminDefined(false);
			}
			// user Super Admin
			$findUser = $xmldata->xpath("/FMSERVERS/FMBASE[@default='default']/user[@superadmin='".$superadmin."']");
			if(count($findUser) > 0) {
				reset($findUser);
				$attr = current($findUser)->attributes();
				foreach($checkSA as $nomvar => $champ) {
					if(isset($attr[$champ])) {
						$this->$nomvar = $attr[$champ];
					} else {
						$this->addError('Données administrateur insuffisantes : '.$champ.'.');
						$this->$nomvar = null;
						$this->setSadminDefined(false);
						break(2);
					}
				}
			} else {
				$this->addError('Aucun administrateur trouvé.');
				$this->setSadminDefined(false);
			}
		} else {
			$this->addError('Fichier de paramétrage XML pour FileMaker API non trouvé.');
			$this->setSadminDefined(false);
		}
		// logge SAdmin
		return $this->log_sadmin();
	}

	/**
	 * définit l'utilisateur
	 * @param object User / string $userOrLogin
	 * @param string $pass
	 * @return boolean
	 */
	public function define_user($userOrLogin, $pass = null) {
		// echo("Classe : ".get_class($userOrLogin)."<br />");
		if(is_object($userOrLogin)) {
			$this->loguser = $userOrLogin->getFmlogin();
			$this->logpass = $userOrLogin->getFmpass();
			$this->setUserDefined(true);
		} else if($pass !== null) {
			$this->loguser = $userOrLogin;
			$this->logpass = $pass;
			$this->setUserDefined(true);
		} else {
			$this->setUserDefined(false);
		}
		return $this->isUserDefined();
	}


	/**
	 * log l'utilisateur
	 * @param $login
	 * @param $pass
	 * @return boolean
	 */
	public function log_user($userOrLogin = null, $pass = null) {
		if((($userOrLogin !== null) && ($pass !== null)) || (is_object($userOrLogin))) {
			$this->define_user($userOrLogin, $pass);
		}
		if($this->isUserDefined()) {
			$this->APIfm = new \FileMaker($this->dbname);
			$this->APIfm->setProperty('username', $this->loguser);
			$this->APIfm->setProperty('password', $this->logpass);
			$this->setUserLogg(true);
		} else $this->setUserLogg(false);

		return $this->isUserLogged();
	}

	/**
	 * log sadmin
	 * @return boolean
	 */
	public function log_sadmin($BDD = null) {
		if($BDD === null) $BDD = $this->dbname;
		if($this->isSadminDefined() && $this->dbname !== null) {
			$this->APIfmSA = new \FileMaker($BDD);
			$this->APIfmSA->setProperty('username', $this->dbuserSA);
			$this->APIfmSA->setProperty('password', $this->dbpassSA);
			$this->setSadminLogged(true);
		} else $this->setSadminLogged(false);

		return $this->isSadminLogged();
	}


	// ***********************
	// ERRORS
	// ***********************

	/**
	 * Ajoute une erreur / insère la date/heure automatiquement
	 * @param array/string
	 */
	protected function addError($message) {
		$this->addErrors($message);
	}


	/**
	 * Ajoute une erreur / insère la date/heure automatiquement
	 * @param array/string
	 * @param boolean $putFlash / insère également en données flashbag si true (par défaut)
	 * @return filemakerservice
	 */
	protected function addErrors($messages, $putFlash = true) {
		$time = new \Datetime();
		if(is_string($messages)) $messages = array($messages);
		foreach ($messages as $message) {
			$this->errors[] = array($message, $time);
			$this->container->get("session")->getFlashBag()->add("FMerror", $message." (".$time->format("H:i:s - Y/m/d").")");
		}
		return $this;
	}

	/**
	 * Renvoie la liste des erreurs
	 * @return array errors
	 */
	protected function getErrors() {
		return $this->errors;
	}

	/**
	 * Affiche les erreurs (Boostrap required)
	 * @return filemakerservice
	 */
	protected function affErrors() {
		echo("<br /><br /><div class='container'><table class='table table-bordered table-hover table-condensed'>");
		foreach ($this->errors as $key => $error) {
			echo("	<tr>");
			echo("		<td>".$error[0]."</td>");
			echo("		<td>".$error[1]->format("H:i:s - Y/m/d")."</td>");
			echo("	</tr>");
		}
		echo("</table></div><br />");
		return $this;
	}

	/**
	 *
	 */
	protected function affAll() {
		echo("<pre>");
		var_dump($this->listOfDatabases);
		var_dump($this->listScripts);
		var_dump($this->listOfModels);
		echo("</pre>");
	}

	// ***********************
	// SETTERS
	// ***********************

	/**
	 * Change défini de Super Admin
	 * @param boolean
	 * @return filemakerservice
	 */
	protected function setSadminDefined($defined) {
		if(is_bool($defined)) $this->SA_defined = $defined;
			else $this->SA_defined = false;
		return $this;
	}

	/**
	 * Change état log SAdmin
	 * @param boolean $log
	 * @return filemakerservice
	 */
	public function setSadminLogged($log) {
		if(is_bool($log)) $this->SA_logged = $log;
			else $this->SA_logged = false;
		return $this;
	}


	/**
	 * Change defini de user
	 * @param boolean
	 * @return filemakerservice
	 */
	protected function setUserDefined($defined) {
		if(is_bool($defined)) $this->user_defined = $defined;
			else $this->user_defined = false;
		return $this;
	}

	/**
	 * Change connexion statut de user
	 * @param boolean
	 * @return filemakerservice
	 */
	protected function setUserLogg($log) {
		if(is_bool($log)) $this->user_logged = $log;
			else $this->user_logged = false;
		return $this;
	}



	// ***********************
	// GETTERS
	// ***********************

	/**
	 * Nom du service
	 * @return string
	 */
	public function getName() {
		return "filemaker-service";
	}

	/**
	 * Nom de la base
	 * @return string
	 */
	public function getBasename() {
		return $this->dbname;
	}

	/**
	 * username utilisateur
	 * @return string
	 */
	public function getUsername() {
		return $this->username;
	}

	/**
	 * login utilisateur
	 * @return string
	 */
	public function getUserlog() {
		return $this->loguser;
	}

	/**
	 * Super Admin défini ?
	 * @return boolean
	 */
	public function isSadminDefined() {
		return $this->SA_defined;
	}

	/**
	 * user défini ?
	 * @return boolean
	 */
	public function isUserDefined() {
		return $this->user_defined;
	}

	/**
	 * Super Admin connecté ?
	 * @return boolean
	 */
	public function isSadminLogged() {
		return $this->SA_logged;
	}

	/**
	 * user connecté ?
	 * @return boolean
	 */
	public function isUserLogged() {
		return $this->user_logged;
	}







	/**
	 * Renvoie la liste des lieux
	 * @return array
	 */
	public function getLieux() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->APIfm->newFindAllCommand('Lieu_Liste');
			$this->FMfind->addSortRule('cle', 1, FILEMAKER_SORT_DESCEND);
			return $this->getRecords($this->FMfind->execute());
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	/**
	 * Renvoie la liste des lieux
	 * @return array
	 */
	public function getRapports() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->APIfm->newFindAllCommand('Rapports_Local');
			$this->FMfind->addSortRule('Fk_Id_Local', 1, FILEMAKER_SORT_DESCEND);
			return $this->getRecords($this->FMfind->execute());
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	/**
	 * Renvoie la liste des locaux d'un ou plusieurs lieux
	 * @param array $lieux
	 * @return array
	 */
	public function getLocauxByLieux($lieux = null) {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->APIfm->newFindAllCommand('Locaux_IPAD');
			$this->FMfind->addSortRule('ref_local', 1, FILEMAKER_SORT_DESCEND);
			return $this->getRecords($this->FMfind->execute());
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	/**
	 * Renvoie la liste des affaires
	 * @return array
	 */
	public function getAffaires() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->APIfm->newFindAllCommand('Projet_liste');
			$this->FMfind->addSortRule('date_projet', 1, FILEMAKER_SORT_DESCEND);
			return $this->getRecords($this->FMfind->execute());
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	/**
	 * Renvoie la liste des tiers
	 * @return array
	 */
	public function getTiers() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->APIfm->newFindAllCommand('Tiers_Liste');
			$this->FMfind->addSortRule('ref', 1, FILEMAKER_SORT_DESCEND);
			return $this->getRecords($this->FMfind->execute());
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	// Informations de structure de base de données

	/**
	 * Renvoie la liste des bases de données
	 * @return array
	 */
	public function getDatabases() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->APIfm->setProperty('hostspec', 'http://localhost');
			$this->FMfind = $this->APIfm->listDatabases();
			// $result = $this->FMfind->execute();
			if ($this->APIfm->isError($this->FMfind)) {
			    $records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
		} else {
			$records = "Utilisateur non connecté.";
		}
		return $records;
	}

	/**
	 * Renvoie la liste des bases de données via SAdmin
	 * @return array
	 */
	public function getDatabasesSAdmin() {
		// echo("FMSA : ".get_class($this->APIfmSA)."<br />");
		if($this->isSadminLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->APIfmSA->setProperty('hostspec', 'http://localhost');
			$this->FMfind = $this->APIfmSA->listDatabases();
			// $result = $this->FMfind->execute();
			if ($this->APIfmSA->isError($this->FMfind)) {
				$records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
		} else {
			$records = "Super Admin non connecté.";
		}
		return $records;
	}

	/**
	 * Renvoie la liste des scripts
	 * @return array
	 */
	public function getScripts() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			// $this->APIfm->setProperty('hostspec', 'http://localhost');
			$this->FMfind = $this->APIfm->listScripts();
			// $result = $this->FMfind->execute();
			if ($this->APIfm->isError($this->FMfind)) {
			    $records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
			return $records;
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	/**
	 * Renvoie la liste des scripts via SAdmin
	 * @return array
	 */
	public function getScritpsByBddSAdmin($BDD) {
		// Create FileMaker_Command_Find on layout to search
		// $this->APIfm->setProperty('hostspec', 'http://localhost');
		if($this->isSadminLogged() === true) {
			$this->log_sadmin($BDD);
			$this->FMfind = $this->APIfmSA->listScripts();
			// $result = $this->APIfmSA->execute();
			if ($this->APIfmSA->isError($this->FMfind)) {
			    $records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
		} else {
			$records = "Super Admin non connecté.";
		}
		return $records;
	}

	/**
	 * Renvoie la liste des modèles
	 * @return array
	 */
	public function getLayouts() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->APIfm->setProperty('hostspec', 'http://localhost');
			$this->FMfind = $this->APIfm->listLayouts();
			// $result = $this->FMfind->execute();
			if ($this->APIfm->isError($this->FMfind)) {
			    $records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
			return $records;
		} else {
			$records = "Utilisateur non connecté.";
		}
		return $records;
	}

	/**
	 * Renvoie la liste des modèles
	 * @return array
	 */
	public function getLayoutsByBddSAdmin($BDD) {
		if($this->isSadminLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->log_sadmin($BDD);
			$this->APIfmSA->setProperty('hostspec', 'http://localhost');
			$this->FMfind = $this->APIfmSA->listLayouts();
			// $result = $this->FMfind->execute();
			if ($this->APIfmSA->isError($this->FMfind)) {
			    $records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
		} else {
			$records = "Super Admin non connecté.";
		}
		return $records;
	}

	/**
	 * Renvoie la version de l'API
	 * @return string (?)
	 */
	public function getAPIVersion() {
		return $this->APIfm->getAPIVersion();
	}

}