<?php
// filemakerBundle/services/filemakerservice.php

namespace filemakerBundle\services;

use Symfony\Component\DependencyInjection\ContainerInterface;

class filemakerservice {

	protected $data = array();
	protected $container;			// ContainerInterface
	protected $APIfm;				// objet FileMaker (accès user logged)
	protected $APIfmSADMIN;			// objet FileMaker (accès Super Admin)
	protected $FMfind;				//

	protected $APIfm_paramfile;		// fichier de paramètres de l'API FileMaker

	protected $dbname;				// nom de la base
	protected $dbuser;				// login du Super Admin
	protected $dbpass;				// mot de passe du Super Admin
	protected $dbdescribe;			// description de la bd
	protected $username;			// username
	protected $loguser;				// login user
	protected $logpass;				// pass user

	protected $SA_defined 		= false;	// Super Admin trouvé ou non (boolean)
	protected $user_defined 	= false;	// Super Admin trouvé ou non (boolean)
	protected $SA_logged 		= false;	// Super Admin connecté ou non (boolean)
	protected $user_logged 		= false;	// user connecté ou non (boolean)

	protected $errors			= array();	// messages d'erreur

	// ATTENTION :
	// AJOUTER le namespace dans l'API FileMaker : namespace filemakerBundle\services;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
		$this->APIfm_paramfile = __DIR__."/../../../../../app/config/parameters_fm.xml";
		require_once(__DIR__."/../FM/FileMaker.php");
		if($this->param_findSadmin() === true) {
			// echo('fmBDname : '.$this->dbname.'<br />');
			$this->APIfmSADMIN = new \FileMaker($this->dbname);
			$this->APIfmSADMIN->setProperty('username', $this->dbuser);
			$this->APIfmSADMIN->setProperty('password', $this->dbpass);
			// echo("Login Super Admin : ".$this->dbuser."<br />");
			// echo("Passe Super Admin : ".$this->dbpass."<br />");
			$this->setSadminLogg(true);
		} else {
			$this->addError('Connexion FM impossible.');
			$this->setSadminLogg(false);
		}
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
	 * @param $file / chemin et nom du fichier xml
	 * @param $username / permet de changer le username recherché ('sadmin' par défaut)
	 * @return boolean (true si succès)
	 */
	protected function param_findSadmin($file = null, $superadmin = 'default') {
		$this->setSadminDefined(true);
		$checkServer = array(
			"dbname" => "nom",
			"dbdescribe" => "descriptif",
			);
		$checkUser = array(
			"username" => "username",
			"dbuser" => "login",
			"dbpass" => "passe",
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
				foreach($checkUser as $nomvar => $champ) {
					if(isset($attr[$champ])) {
						$this->$nomvar = $attr[$champ];
					} else {
						$this->addError('Données administrateur insuffisantes.');
						$this->$nomvar = null;
						$this->setSadminDefined(false);
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

		return $this->isSadminDefined();
	}

	/**
	 * log l'utilisateur
	 * @param object User / string $userOrLogin
	 * @param string $pass
	 * @return boolean
	 */
	public function define_user($userOrLogin, $pass = null) {
		if(is_object($userOrLogin)) {
			$this->loguser = $userOrLogin->getFmlogin();
			$this->logpass = $userOrLogin->getFmpass();
		} else {
			$this->loguser = $userOrLogin;
			$this->logpass = $pass;
		}
		$this->setUserDefined(true);

		// echo("Login user : ".$this->loguser."<br />");
		// echo("Passe user : ".$this->logpass."<br />");
		return $this->isUserDefined();
	}


	/**
	 * log l'utilisateur
	 * @param $login
	 * @param $pass
	 */
	public function log_user($userOrLogin = null, $pass = null) {
		if((($userOrLogin !== null) && ($pass !== null)) || is_object($userOrLogin)) $this->define_user($userOrLogin, $pass);
		if($this->isUserDefined()) {
			$this->APIfm = new \FileMaker($this->dbname);
			$this->APIfm->setProperty('username', $this->loguser);
			$this->APIfm->setProperty('password', $this->logpass);
			$this->setUserLogg(true);
		} else $this->setUserLogg(false);

		return $this->isUserLogged();
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

	// ***********************
	// SETTERS
	// ***********************

	/**
	 * Change défini de Super Admin
	 * @param boolean
	 */
	protected function setSadminDefined($defined) {
		$this->SA_defined = $defined;
	}

	/**
	 * Change defini de user
	 * @param boolean
	 */
	protected function setUserDefined($defined) {
		$this->user_defined = $defined;
	}

	/**
	 * Change connexion statut de Super Admin
	 * @param boolean
	 */
	protected function setSadminLogg($statut) {
		$this->SA_logged = $statut;
	}

	/**
	 * Change connexion statut de user
	 * @param boolean
	 */
	protected function setUserLogg($statut) {
		$this->user_logged = $statut;
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
			$this->FMfind =& $this->APIfm->newFindAllCommand('Lieu_IPAD');
			$this->FMfind->addSortRule('cle', 1, FILEMAKER_SORT_DESCEND);
			return getRecords($this->FMfind->execute());
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
			return getRecords($this->FMfind->execute());
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
			return getRecords($this->FMfind->execute());
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
			return getRecords($this->FMfind->execute());
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
			return $records;
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	/**
	 * Renvoie la liste des bases de données
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
			return $records;
		}
	}

	/**
	 * Renvoie la version de l'API
	 * @return string (?)
	 */
	public function getAPIVersion() {
		return $this->APIfm->getAPIVersion();
	}

}