<?php
// filemakerBundle/services/filemakerservice.php

namespace filemakerBundle\services;

use Symfony\Component\DependencyInjection\ContainerInterface;

class filemakerservice {

	protected $container;						// ContainerInterface
	protected $serviceSess;						// Session data
	protected $sessionServiceNom;				// Nom des données en session

	protected $DEVdata = array();				// Données de développement

	// bases FM
	protected $FMbase_paramfile;				// fichier de paramètres de l'API FileMaker
	protected $FMfind = array();				// Résultat de recherche FM
	protected $FMbase = array();				// Objets FileMaker : 
												// FMbase['serveur']['base']['SA_access']['objet']
												// FMbase['serveur']['base']['US_access']['objet']

	// Données globales
	protected $SERVER = array(); 				// Liste des serveurs
	protected $defaultErrSERVER = 'http://localhost'; 	 // serveur FM par défaut en cas d'erreur et donc aucun serveur dispo
	protected $defaultErrSERVERname = "@localhost";		// nom de serveur FM par défaut en cas d'erreur… etc…

	protected $currentSERVER; 					// nom du serveur FM en cours
	protected $defaultSERVER; 					// nom du serveur FM par défaut
	protected $currentBASE = array();			// nom de la base en cours
	protected $defaultBASE = array();			// nom de la base par défaut

	protected $globalErrors = array();			// Erreurs du service filemakerservice
	protected $sourceDescription;				// string : désignation de la source des données de bases

	protected $defaultValueON 	= true;			// valeur ON de l'attribut "default"
	protected $defaultValueOFF 	= false;		// valeur OFF de l'attribut "default"

	// USER
	protected $user_defined;
	protected $user_logged;

	// DEV et environnement
	protected $DEV = true;
	protected $env;

	// ATTENTION :
	// AJOUTER le namespace dans l'API FileMaker : namespace filemakerBundle\services;

	public function __construct(ContainerInterface $container) {
		$this->container 			= $container;
		$this->serviceSess 			= $this->container->get('request')->getSession();
		$this->sessionServiceNom	= "filemakerservice";
		$this->sourceDescription 	= "fichier XML";
		$this->FMbase_paramfile 	= __DIR__."/../../../../../app/config/parameters_fm.xml";
		require_once(__DIR__."/../FM/FileMaker.php");
		// get environnement
		$this->env = $this->container->get('kernel')->getEnvironment();
		$this->DEVdata["Environnement"] = $this->env;
		if($this->env === "prod") $this->DEV = false;
		$this->echoDev("environnement : ".$this->env);
		// initialisation
		$this->initializeService();

		// TEST
		// foreach ($this->getListOfServersNames() as $server) {
		// 	$this->echoDev("<h3>Bases du serveur ".$server."</h3>", "");
		// 	$this->vardumpDev($this->getListOfBases($server));
		// }
		// $this->affErrors();
		return $this;
	}

	public function __destruct() {
		// sauvegarde en session
		// $this->putDataInSession();
		// $this->putDataInSession($this->DEVdata, "filemaker_DEV");
		// affiche les erreurs
		$this->affErrors();
	}

	/**
	 * Initialise les données du service
	 * dans le fichier app/config/parameters_fm.xml
	 * @param $file / chemin et nom du fichier xml
	 * @param $forceLoad / recharge forcée (si false : récupère les données en session si elles existent)
	 * @return boolean (true si succès)
	 */
	protected function initializeService($file = null, $forceLoad = false) {
		if($this->isDataInSession() === false) {
			$this->DEVdata["Chargement"] = "Scan servers & databases";
			if($file === null) $file = $this->FMbase_paramfile;
			if(file_exists($file)) {
				$xmldata = simplexml_load_file($file);
				// $this->vardumpDev($xmldata, "Données brutes ".$this->sourceDescription);
				$serv = $xmldata->xpath("/FMSERVERS/SERVER");
				// $this->vardumpDev($serv, "Données ".$this->sourceDescription);
				$defaultSERVER = $this->defaultValueOFF;
				$firstServer = null;
				// SERVEURS : début
				if(count($serv) > 0) {
					foreach($serv as $ssh) {
						$attr = $ssh->attributes();
						if(isset($attr['ip'])) {
							$ipp = trim($attr['ip']);
							// Si nom n'existe pas on lui dont le nom d'Ip
							if(!isset($attr['nom']) || strlen(trim($attr['nom'])) < 1) $nom = $ipp;
								else $nom = trim($attr['nom']);
							// modifie le nom s'il existe déjà pour un autre serveur
							if($firstServer === null) $firstServer = $nom;
							$mmnom = $nom;
							$tstn = 1;
							// si le nom existe déjà ET que l'ip est bien différente
							while(array_key_exists($nom, $this->SERVER) && ($this->SERVER[$nom] != $ipp)) {
								$nom = $mmnom."#".$tstn++;
							}
							// Serveur : description du serveur + son statut
							// ip / databases associées + leurs statuts
							$this->SERVER[$nom]['ip'] = $ipp;
							$this->SERVER[$nom]['errors'] = array();
							$this->SERVER[$nom]['default'] = $this->defaultValueOFF;
							$this->SERVER[$nom]['current'] = $this->defaultValueOFF;
							// recherche de serveur par défaut - il ne peut y en avoir qu'un !
							if($defaultSERVER === $this->defaultValueOFF) {
								if(trim($attr['default']) === 'default') {
									$defaultSERVER 					= $this->defaultValueON;
									$this->SERVER[$nom]['default'] 	= $this->defaultValueON;
									$this->SERVER[$nom]['current'] 	= $this->defaultValueON;
								}
							}
							// recherche de statut
							$listDBServer = $this->getListOfSrvDatabases($this->SERVER[$nom]['ip']);
							if(is_string($listDBServer)) {
								// Serveur non accessible
								$this->SERVER[$nom]['statut'] = false;
								$this->SERVER[$nom]['errors'][] = $listDBServer;
							} else {
								$this->SERVER[$nom]['nom'] = $nom;
								$this->SERVER[$nom]['statut'] = true;
								// databases en description
								$this->SERVER[$nom]['databases'] = $this->getAndTestBASES($ssh->xpath("FMBASE"), $nom, $listDBServer);
								// Ajout des paramètres de connexion aux bases
								$this->getAndMakeConnexions($ssh, $nom);
								// Ajout des modèles
								$this->getAntTestModels($nom);
								// Ajout des scripts
								$this->getAntTestScripts($nom);
								// vérifie qu'il y a bien une base par défaut, sinon désigne la première trouvée
								$this->fixDefaultAndCurrentBase($nom);
							}
						}
					}
					// Sauvegarde en session
					$this->putDataInSession();
				} else {
					$this->SERVER[$this->defaultErrSERVERname] = $this->defaultErrSERVER;
					// $sdb = $this->getListOfSrvDatabases($this->SERVER[$this->defaultErrSERVERname]);
					$this->addError('Description de serveur non trouvée.<br>Selection du serveur par défaut : '.$this->defaultErrSERVER." (\"".$this->defaultErrSERVERname."\")");
				}
			} else {
				$this->SERVER[$this->defaultErrSERVERname] = $this->defaultErrSERVER;
				$this->addError($this->sourceDescription." non trouvé. Chargement des données de serveurs impossible");
			}
		} else {
			// chargement des données en session
			$this->getFilemakerserviceDataInSession(null, true);
			$this->DEVdata["Chargement"] = "Chargement depuis session";
		}
		// attribue le premier serveur par défaut s'il n'y en a pas eu
		if($defaultSERVER === $this->defaultValueOFF) $this->SERVER[$firstServer]['default'] = $this->defaultValueON;
		$this->vardumpDev($this->SERVER, "Liste des serveurs/bases mémorisées");
		return $this->auMoinsUneBaseValide();
	}

	/**
	 * Enregistre les données de service en session
	 * @param mixed $data - données à enregistrer ($this->SERVER par défaut si null)
	 * @param string $nom - nom des données ($this->sessionServiceNom par défaut)
	 * @return filemakerservice
	 */
	protected function putDataInSession($data = null, $nom = null) {
		if($data === null) $data = $this->SERVER;
		if($nom === null) $nom = $this->sessionServiceNom;
		$this->serviceSess->set($nom, $data);

		// DEV
		$this->DEVdata['Serveur courant'] = $this->getCurrentSERVER();
		$this->DEVdata['Base courante'] = $this->getCurrentBASE();
		$this->DEVdata['Ip courant'] = $this->getCurrentIP();
		$this->DEVdata['SAdmin login'] = $this->getCurrentSAdminLogin();
		$this->DEVdata['SAdmin password'] = $this->getCurrentSAdminPasse();
		$this->serviceSess->set("filemaker_DEV", $this->DEVdata);

		return $this;
	}

	/**
	 * Vérifie si les données du service sont en session
	 * @param string $nom - nom des données ($this->sessionServiceNom par défaut)
	 * @return boolean
	 */
	protected function isDataInSession($nom = null) {
		if($nom === null) $nom = $this->sessionServiceNom;
		if(count($this->serviceSess->get($nom)) > 0) return true;
			else return false;
	}

	protected function getAntTestModels($SERVnom) {
		$SERVnom = $this->getServerByNom($SERVnom);
		if(array_key_exists($SERVnom, $this->SERVER)) {
			// $this->echoDev('Vérification des modèles pour '.$SERVnom);
			foreach($this->SERVER[$SERVnom]['databases']['valids'] as $BASEnom => $base) {
				$this->echoDev('Vérification des modèles pour '.$BASEnom." (".$SERVnom.").");

			}
		}
	}

	protected function getAntTestScripts($SERVnom) {
		
	}

	/**
	 * Ajoute les paramètres de connexion (en SAdmin) dans les données $this->SERVER
	 * @param simpleXMLobject $ssh
	 * @param string $SERVnom - nom du serveur
	 */
	protected function getAndMakeConnexions($ssh, $SERVnom) {
		foreach($ssh->xpath("FMBASE") as $base) {
			$baseAttr = $base->attributes();
			$BASEnom = trim($baseAttr['nom']);
			if(isset($this->SERVER[$SERVnom]["databases"]["valids"][$BASEnom])) {
				$this->SERVER[$SERVnom]["databases"]["valids"][$BASEnom]["sadmin"] = array();
				$this->SERVER[$SERVnom]["databases"]["valids"][$BASEnom]["users"] = array();
				foreach($base->xpath("user") as $user) {
					$userAttr = $user->attributes();
					$USERnom = trim($userAttr['username']);
					$this->echoDev("Base ".$BASEnom." = User ".$USERnom);
					// vérifie si les paramètres requis sont présents
					$missingRequired = false;
					foreach($this->getAttribsFromUser() as $nat => $vat) {
						if((!isset($userAttr[$nat]) || (trim($userAttr[$nat] === ""))) && $vat === true) $missingRequired = true;
					}
					if($missingRequired === false) {
						if(trim($userAttr['superadmin']) === "default") $userStatut = "sadmin";
							else $userStatut = "users";
						// Enregistrement en database
						foreach ($userAttr as $nomatt => $valatt) {
							$this->echoDev("Attribut user ".$USERnom." : ".trim($nomatt)." = ".trim($valatt));
							$this->SERVER[$SERVnom]["databases"]["valids"][$BASEnom][$userStatut][$USERnom][$nomatt] = trim($valatt);
						}
					}
				}
				// vérifie si la base a bien des données de connexion "sadmin", sinon on la passe en "unvalids"
				if(count($this->SERVER[$SERVnom]["databases"]["valids"][$BASEnom]["sadmin"]) < 1) {
					$err = "Base ".$BASEnom." déclarée invalide : paramètres sadmin non trouvés.";
					$this->SERVER[$SERVnom]["databases"]["unvalids"][$BASEnom] = $this->SERVER[$SERVnom]["databases"]["valids"][$BASEnom];
					$this->SERVER[$SERVnom]["databases"]["unvalids"][$BASEnom]["errors"] = array();
					$this->SERVER[$SERVnom]["databases"]["unvalids"][$BASEnom]["errors"][] = $err;
					$this->SERVER[$SERVnom]["databases"]["unvalids"][$BASEnom]["statut"] = false;
					unset($this->SERVER[$SERVnom]["databases"]["valids"][$BASEnom]);
					$this->echoDev($err);
					$this->addError($err);
				}
			}
		}
	}

	/**
	 * renvoie la liste des bases valides en comparant avec celles disponibles sur le serveur
	 * @param array $liste of simpleXml objects
	 * @param string $SERVnom - nom du serveur (on devrait choisir l'IP, mais cela évite un nouvel accès à la base)
	 * @param array $listDBServer - liste des serveurs trouvés sur la base
	 * @return array
	 */
	protected function getAndTestBASES($liste, $SERVnom, $listDBServer) {
		// initialisation du tableau $access
		$access = array();
		$access['valids'] = array();
		$access['unvalids'] = array();
		$access['scan'] = $listDBServer;
		// récupération des bases
		// $this->vardumpDev($listDBServer, "Bases existantes pour test :");
		if(count($liste) > 0) {
			// au moins une base dans la liste…
			// $this->echoDev(count($liste)." bases à tester…");
			// $firstBase = null;
			$defaultBASE = false;
			foreach($liste as $base) {
				$attrBase = $base->attributes();
				if(isset($attrBase['nom']) && (trim($attrBase['nom']) !== "")) {
					// si un attribut 'nom' existe
					$nomBASEbcl = trim($attrBase['nom']);
					// mémo du nom de la première base trouvée
					// if($firstBase === null) $firstBase = $nomBASEbcl;
					// $this->echoDev("test de la base ".$nomBASEbcl."…");
					if(in_array($nomBASEbcl, $listDBServer)) {
						// la base existe
						// $this->echoDev("la base ".$nomBASEbcl." existe sur le serveur.");
						$access['valids'][$nomBASEbcl] = array();
						foreach($this->getAttribsFromXML() as $nom => $required) {
							// valeur par défaut ou null
							$attbcl = $this->getDefautVal($nom, $SERVnom);
							if(($required === true) && (!isset($attrBase[$nom])) && ($attbcl === null)) {
								// élément requis non présent : statut en erreur sur cette base
								// et aucune valeur par défaut n'existe
								$message = "Base ".$nom." de ".$SERVnom." incomplète : échec sur cette base.";
								$access['unvalids'][$nomBASEbcl]['nom'] = $nomBASEbcl;
								$access['unvalids'][$nomBASEbcl]['errors'] = array();
								$access['unvalids'][$nomBASEbcl]['errors'][] = $message;
								$access['unvalids'][$nomBASEbcl]['statut'] = false;
								$this->echoDev($message);
								$this->addError($message);
								break(1);
							} else {
								// élément présent
								if(isset($attrBase[$nom])) $attbcl = trim($attrBase[$nom]);
								// exception pour default="default"
								if($nom == "default") {
									if($defaultBASE === false && $attbcl == "default") {
										$this->echoDev("base par défaut / courante pour ".$SERVnom." : ".$nomBASEbcl);
										$defaultBASE = true;
										$access['valids'][$nomBASEbcl]["default"] = $this->defaultValueON;
										$access['valids'][$nomBASEbcl]["current"] = $this->defaultValueON;
										// définit la base par défaut
										$this->setDefaultBASE($SERVnom, $nomBASEbcl);
										// définit la base courante aussi, du coup
										$this->setCurrentBASE($SERVnom, $nomBASEbcl);
									} else {
										$access['valids'][$nomBASEbcl]["default"] = $this->defaultValueOFF;
										$access['valids'][$nomBASEbcl]["current"] = $this->defaultValueOFF;
									}
								} else if(($attbcl !== null) || (strlen($attbcl) > 0)) {
									//$this->echoDev("New param base : ".$nomBASEbcl." => ".$nom." = ".$attbcl);
									$access['valids'][$nomBASEbcl][$nom] = $attbcl;
								}
							}
						}
						foreach($attrBase as $nom => $val) if(!array_key_exists($nom, $access['valids'][$nomBASEbcl])) {
							// ajout des autres attributs non requis s'il en existe
							if(is_string($val)) $access['valids'][$nomBASEbcl][$nom] = trim($val);
								else $access['valids'][$nomBASEbcl][$nom] = $val;
						}
					} else {
						// base non présente dans la liste des bases trouvées sur le serveur
						$message = "Base <strong>".$nomBASEbcl."</strong> non présente sur le serveur <strong>".$SERVnom."</strong>.";
						$access['unvalids'][$nomBASEbcl]['nom'] = $nomBASEbcl;
						$access['unvalids'][$nomBASEbcl]['errors'] = array();
						$access['unvalids'][$nomBASEbcl]['errors'][] = $message;
						$access['unvalids'][$nomBASEbcl]['statut'] = false;
						$this->addError($message);
					}
				}
			}
			// attribue par défaut la première base si ça n'a pas été fait
			// $testDefB = false;
			// foreach ($access['valids'] as $baseValid) {
			// 	if($baseValid["default"] === $this->defaultValueON) $testDefB = true;
			// }
			// if($testDefB === false) {
			// 	$access['valids'][$firstBase]["default"] = $this->defaultValueON;
			// 	$this->echoDev("base par défaut pour ".$SERVnom." : ".$firstBase);
			// }
			return $access;
		} else {
			return false;
		}
	}

	/**
	 * Liste des éléments requis pour données User (dans XML)
	 * @return array
	 */
	protected function getAttribsFromUser() {
		return array(
			"username" 			=> true,
			"login" 			=> true,
			"passe"				=> true,
			"superadmin"		=> false,
		);
	}

	/**
	 * Liste des éléments requis pour données XML
	 * @return array
	 */
	protected function getAttribsFromXML() {
		return array(
			"nom" 			=> true,
			"descriptif" 	=> false,
			"hostspec"		=> false,
			"default"		=> false,
			"current"		=> false,
			"statut"		=> false,
		);
	}

	/**
	 * Liste des éléments requis pour données XML
	 * @return array
	 */
	protected function getDefautVal($nom, $SERVnom) {
		$defaultVals = array(
			"descriptif" 	=> "(aucun descriptif)",
			"hostspec"		=> $this->SERVER[$SERVnom]['ip'],
			"default"		=> $this->defaultValueOFF,
			"statut"		=> true,
		);
		if(array_key_exists($nom, $defaultVals)) return $defaultVals[$nom];
			else return null;
	}

	/**
	 * Définit une base par défaut/courante s'il n'en existe pas
	 * @param string $SERVnom
	 */
	protected function fixDefaultAndCurrentBase($SERVnom) {
		if(isset($this->SERVER[$SERVnom]['databases']['valids'])) {
			if(count($this->SERVER[$SERVnom]['databases']['valids']) > 0) {
				$testDefB = false;
				$firstBase = null;
				foreach ($this->SERVER[$SERVnom]['databases']['valids'] as $BASEnom => $baseValid) {
					if($firstBase === null) $firstBase = $BASEnom;
					if($baseValid["default"] === $this->defaultValueON) $testDefB = true;
				}
				if($testDefB === false) {
					$this->SERVER[$SERVnom]['databases']['valids'][$firstBase]["default"] = $this->defaultValueON;
					$this->SERVER[$SERVnom]['databases']['valids'][$firstBase]["current"] = $this->defaultValueON;
					$this->echoDev("base par défaut / courante pour ".$SERVnom." : ".$firstBase);
				}
			}
		}
	}

	/**
	 * Renvoie true si au moins une base est valide, sur l'ensemble des serveurs… donc on peut bosser, quoi…
	 * @param string/array $serveurs - nom du serveur ou array des serveurs / Si null : teste tous les serveurs
	 * @return boolean
	 */
	protected function auMoinsUneBaseValide($serveurs = null) {
		if(is_string($serveurs)) $serveurs = array($serveurs);
		if($serveurs === null) $serveurs = $this->getListOfServersNames();
		$cumulBV = 0;
		$cumulBI = 0;
		foreach($serveurs as $SERVnom) if(isset($this->SERVER[$SERVnom])) {
			$this->echoDev("<h3>Serveur ".$SERVnom."</h3>", "");
			$this->echoDev("> Bases valides : ".count($this->SERVER[$SERVnom]['databases']['valids']));
			$this->echoDev("> Bases non valides : ".count($this->SERVER[$SERVnom]['databases']['unvalids']));
			$cumulBV += count($this->SERVER[$SERVnom]['databases']['valids']);
			$cumulBI += count($this->SERVER[$SERVnom]['databases']['unvalids']);
		} else {
			$this->echoDev("Test bases valides globales : serveur non existant ".$SERVnom);
		}
		$this->echoDev("<h3>Tous serveurs</h3>", "");
		$this->echoDev("> Bases valides : ".$cumulBV);
		$this->echoDev("> Bases non valides : ".$cumulBI);
		if($cumulBV > 0) return true;
			else return false;
		// return $cumulBV > 0 ? true : false;
	}

	/**
	 * Récupère les données ou le message d'erreur
	 * @param $result
	 * @return array/string
	 */
	protected function getRecords($result) {
		if ($this->FMbase->isError($result)) {
		    $records = $result->getMessage();
		} else {
			$records = $result->getRecords();
		}
		return $records;
	}

	// /**
	//  * Renvoie la liste des bases de données
	//  * @return array
	//  */
	// public function getDatabases() {
	// 	if($this->isUserLogged() === true) {
	// 		// Create FileMaker_Command_Find on layout to search
	// 		$this->FMbase->setProperty('hostspec', $this->SERVER);
	// 		$this->FMfind = $this->FMbase->listDatabases();
	// 		// $result = $this->FMfind->execute();
	// 		if ($this->FMbase->isError($this->FMfind)) {
	// 		    $records = "Accès non autorisé.";
	// 		} else {
	// 			$records = $this->FMfind;
	// 		}
	// 	} else {
	// 		$records = "Utilisateur non connecté.";
	// 	}
	// 	return $records;
	// }

	/**
	 * Renvoie le nom du serveur (ou false si le serveur n'existe pas)
	 * @param string $SERVnom - nom du serveur
	 * @return string ou false si serveur non existant
	 */
	protected function getServerByNom($SERVnom) {
		if(in_array($SERVnom, $this->getListOfServersNames())) return $SERVnom;
			else return false;
	}

	/**
	 * définit l'utilisateur
	 * @param object User / string $userOrLogin
	 * @param string $pass
	 * @return boolean
	 */
	protected function define_user($userOrLogin, $pass = null) {
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
	 * @param $login - string ou objet User
	 * @param $pass - string ou null
	 * @return boolean
	 */
	public function log_user($userOrLogin = null, $pass = null) {
		$this->setUserLogg(false);
		if((($userOrLogin !== null) && ($pass !== null)) || (is_object($userOrLogin))) {
			if($this->isUserDefined($userOrLogin, $pass)) {
				$this->FMbase = new \FileMaker();
				// GEODIAG_Rapports
				$this->FMbase[$this->getCurrentBASE()]->setProperty('database', $this->dbname);
				$this->FMbase[$this->getCurrentBASE()]->setProperty('hostspec', 'http://localhost');
				$this->FMbase[$this->getCurrentBASE()]->setProperty('username', $this->loguser);
				$this->FMbase[$this->getCurrentBASE()]->setProperty('password', $this->logpass);
				$this->setUserLogg(true);
			}
		}

		return $this->isUserLogged();
	}

	/**
	 * log sadmin
	 * @return boolean
	 */
	public function log_sadmin($BDD = null) {
		if($BDD === null) $BDD = $this->dbname;
		if($this->isSadminDefined() && $this->dbname !== null) {
			$this->FMbase = new \FileMaker($BDD);
			$this->FMbase->setProperty('username', $this->dbuserSA);
			$this->FMbase->setProperty('password', $this->dbpassSA);
			$this->setSadminLogged(true);
		} else $this->setSadminLogged(false);

		return $this->isSadminLogged();
	}

	// ***********************
	// SETTERS privés
	// ***********************

	/**
	 * SERVER Définit le serveur par défaut
	 * @param string $SERVnom - nom du serveur
	 * @return filemakerservice
	 */
	protected function setDefaultSERVER($SERVnom) {
		if(array_key_exists($SERVnom, $this->SERVER)) {
			// annule le serveur par défaut précédent
			foreach($this->SERVER as $nom => $serv) {
				if($serv['default'] === $this->defaultValueON) $this->SERVER[$nom]['default'] = $this->defaultValueOFF;
			}
			// Attribue le nouveau serveur par défaut
			$this->SERVER[$SERVnom]['default'] = $this->defaultValueON;
			// Sauvegarde en session
			$this->putDataInSession();
		} else return false;
		return $this;
	}

	/**
	 * BASE Définit la base par défaut / selon le serveur courant
	 * @param string $nomBASE
	 * @return filemakerservice
	 */
	protected function setDefaultBASE($nomBASE) {
		if(in_array($nomBASE, $this->getListOfBases($this->getCurrentSERVER(), "valids"))) {
			$this->currentBASE = $nomBASE;
		} else return false;
		return $this;
	}

	/**
	 * SERVER Rétablit le serveur par défaut d'origine
	 * @return filemakerservice
	 */
	protected function resetDefaultSERVER() {
		foreach ($this->SERVER as $SERVnom => $SERV) {
			if($SERV['default'] === $this->defaultValueON) $this->defaultSERVER = $SERVnom;
		}
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
	// SETTERS publics
	// ***********************

	/**
	 * SERVER Définit le serveur courant
	 * @param string $SERVnom (si null, définit le serveur par défaut)
	 * @return string (nom du serveur)
	 */
	public function setCurrentSERVER($SERVnom = null) {
		$SERVnom = $this->getServerByNom($SERVnom);
		if($SERVnom !== false) {
			if(array_key_exists($SERVnom, $this->SERVER)) {
				// annule le serveur courant
				foreach ($this->SERVER as $nom => $serv) {
					$this->SERVER[$nom]['current'] = $this->defaultValueOFF;
				}
				// définit le nouveau serveur
				$this->SERVER[$SERVnom]['current'] = $this->defaultValueON;
				// Sauvegarde en session
				$this->putDataInSession();
			} else return false;
			return $SERVnom;
		} else return false;
	}

	/**
	 * BASE Définit la base courante
	 * @param string $SERVnom (si null, définit le serveur par défaut)
	 * @param string $nomBASE (si null, définit la base par défaut)
	 * @return filemakerservice
	 */
	public function setCurrentBASE($SERVnom = null, $nomBASE = null) {
		if($SERVnom === null) {
			$SERVnom = $this->getCurrentSERVER();
		} else {
			$SERVnom = $this->setCurrentSERVER($SERVnom);
		}
		if($SERVnom !== false) {
			if($nomBASE === null) $nomBASE = $this->getDefaultBASE();
			if(in_array($nomBASE, $this->getListOfBases($SERVnom, "valids"))) {
				// annule la base courante (du serveur défini ou courant)
				foreach($this->SERVER[$SERVnom]['databases']['valids'] as $nom => $base) {
					$this->SERVER[$SERVnom]['databases']['valids'][$nom]['current'] = $this->defaultValueOFF;
				}
				// définit la nouvelle bas
				$this->SERVER[$SERVnom]['databases']['valids'][$nomBASE]['current'] = $this->defaultValueON;
				// Sauvegarde en session
				$this->putDataInSession();
			} else return false;
			return $this;
		} else return false;
	}

	// ***********************
	// GETTERS privés
	// ***********************

	/**
	 * Liste des recherches de databases du serveur $serveur
	 * @param string $serveur - ip du serveur
	 */
	protected function getListOfSrvDatabases($SERVnom = null) {
		$fm = new \FileMaker();
		$fm->setProperty('hostspec', $SERVnom);
		$records = $fm->listDatabases();
		if ($fm->isError($records)) {
			$records = "Erreur en accès serveur ".$SERVnom;
		}
		// $this->vardumpDev($records, "Liste des bases trouvées sur le srveur ".$SERVnom);
		return $records;
	}

	/**
	 * Renvoie la liste des types de databases (dans $this->SERVER['databases'])
	 * @return array
	 */
	protected function getListOfTypesOfDatabases() {
		$types = array();
		foreach($this->SERVER as $SERVnom => $server) {
			foreach($server['databases'] as $nomtyp => $typDB) {
				if(!in_array($nomtyp, $types)) $types[] = $nomtyp;
			}
		}
		return $types;
	}

	/**
	 * Renvoie le hostspec du serveur courant (ip)
	 * @return string
	 */
	protected function getCurrentIP() {
		$CS = $this->getCurrentSERVER();
		return $this->SERVER[$CS]['ip'];
	}

	/**
	 * Renvoie le login SAdmin de la base courante
	 * @return string / false si non trouvé
	 */
	protected function getCurrentSAdminLogin() {
		$CS = $this->getCurrentSERVER();
		$CB = $this->getCurrentBASE();
		foreach ($this->SERVER[$CS]['databases']['valids'][$CB]['sadmin'] as $nom => $user) {
			if(trim($user['superadmin']) === 'default') return $user['login'];
		}
		return false;
	}

	/**
	 * Renvoie le password SAdmin de la base courante
	 * @return string / false si non trouvé
	 */
	protected function getCurrentSAdminPasse() {
		$CS = $this->getCurrentSERVER();
		$CB = $this->getCurrentBASE();
		foreach ($this->SERVER[$CS]['databases']['valids'][$CB]['sadmin'] as $nom => $user) {
			if(trim($user['superadmin']) === 'default') return $user['passe'];
		}
		return false;
	}



	// ***********************
	// GETTERS publics
	// ***********************

	/**
	 * Nom du service
	 * @return string
	 */
	public function getName() {
		return "filemakerservice";
	}

	/**
	 * SERVER Renvoie le nom du serveur courant
	 * @return string ou false si aucun
	 */
	public function getCurrentSERVER() {
		foreach($this->SERVER as $nom => $serv) {
			if($serv['current'] === true) return $nom;
		}
		return false;
	}

	/**
	 * SERVER Renvoie le serveur par défaut
	 * @return string ou false si aucun
	 */
	protected function getDefaultSERVER() {
		foreach($this->SERVER as $nom => $serv) {
			if($serv['default'] === true) return $nom;
		}
		return false;
	}

	/**
	 * BASE Nom de la base courante (du serveur courant si serveur non précisé)
	 * @param string $SERVnom
	 * @return string ou false si aucune
	 */
	public function getCurrentBASE($SERVnom = null) {
		if($SERVnom === null) $SERVnom = $this->getDefaultSERVER();
		foreach($this->SERVER[$SERVnom]['databases']['valids'] as $nom => $base) {
			if($base['current'] === $this->defaultValueON) return $nom;
		}
		return false;
	}

	/**
	 * BASE Nom de la base par défaut (du serveur courant si serveur non précisé)
	 * @param string $SERVnom
	 * @return string ou false si aucune
	 */
	public function getDefaultBASE($SERVnom = null) {
		if($SERVnom === null) $SERVnom = $this->getDefaultSERVER();
		foreach($this->SERVER[$SERVnom]['databases']['valids'] as $nom => $base) {
			if($base['default'] === $this->defaultValueON) return $nom;
		}
		return false;
	}

	/**
	 * user défini ?
	 * @return boolean
	 */
	public function isUserDefined() {
		return $this->user_defined;
	}

	/**
	 * user connecté ?
	 * @return boolean
	 */
	public function isUserLogged() {
		return $this->user_logged;
	}

	/**
	 * Récupère les données du service en session
	 * @param string $nom - nom des données ($this->sessionServiceNom)
	 * @return mixed
	 */
	public function getFilemakerserviceDataInSession($nom = null, $loadthem = false) {
		if($nom === null) $nom = $this->sessionServiceNom;
		$data = $this->serviceSess->get($nom);
		if($loadthem === true) $this->SERVER = $data;
		return $data;
	}

	// /**
	//  * Renvoie la liste des serveurs
	//  * @return array
	//  */
	// public function getServers() {
	// 	if($this->isUserLogged() === true) {
	// 		return $this->getListOfServersNames();
	// 	} else return false;
	// }

	// /**
	//  * Renvoie la liste des bases de données
	//  * @return array
	//  */
	// public function getDatabases($SERVnom = null) {
	// 	if($this->isUserLogged() === true) {
	// 		return $this->getListOfServersNames();
	// 	} else return false;
	// }

	/**
	 * liste des noms de serveurs
	 * @return array / string si aucun serveur
	 */
	public function getListOfServersNames() {
		if(count($this->SERVER) < 1) return "Aucun serveur trouvé.";
		$list = array();
		foreach ($this->SERVER as $nom => $ip) {
			$list[] = $nom;
		}
		return $list;
	}

	/**
	 * Renvoie la liste des bases d'un serveur $SERVnom
	 * @param string $SERVnom - nom du serveur (ou serveur par défaut si null)
	 * @param string $statut - statut recherché : "valids" / "unvalids" / "scan"
	 * @return array ou false si aucune ou serveur inexistant
	 */
	public function getListOfBases($SERVnom = null, $statut = 'valids') {
		$types = $this->getListOfTypesOfDatabases();
		if(!in_array($statut, $types)) {
			$statut = 'valids';
			if(!in_array($statut, $types)) {
				reset($types);
				$statut = current($types);
			}
		}
		$SERVnom = $this->getServerByNom($SERVnom);
		if($SERVnom !== false) {
			if(count($this->SERVER[$SERVnom]['databases'][$statut]) < 1) return false;
			$list = array();
			foreach($this->SERVER[$SERVnom]['databases'][$statut] as $nom => $base) {
				$list[] = $nom;
			}
			return $list;
		} else return false;
	}



	// *************************
	// METHODES GÉODEM
	// *************************

	/**
	 * Renvoie la liste des lieux
	 * @return array
	 */
	public function getLieux() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->FMbase->newFindAllCommand('Lieu_Liste');
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
	public function getRapports($etat = 'all') {
		if($this->isUserLogged() === true) {
			$vals = array(0 => "0", 1 => "1");
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->FMbase->newFindCommand('Rapports_Local');
			if(in_array($etat, $vals)) {
				$this->FMfind->addFindCriterion('a_traiter', intval($etat));
			}
			$this->FMfind->addSortRule('id', 1, FILEMAKER_SORT_DESCEND);
			return $this->getRecords($this->FMfind->execute());
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	// public function getRelatedSets() {
	// 	if($this->isUserLogged() === true) {
	// 		// Create FileMaker_Command_Find on layout to search
	// 		$this->FMfind =& $this->FMbase->newFindCommand('Rapports_Local');
	// 		$this->FMfind->addSortRule('id', 1, FILEMAKER_SORT_DESCEND);
	// 		return $this->getRecords($this->FMfind->execute());
	// 	} else {
	// 		$records = "Utilisateur non connecté.";
	// 		return $records;
	// 	}
	// }

	// Exemple
	// $relatedSet = $currentRecord->getRelatedSet(’customers’); /* Exécuté sur chacune des lignes de la table externe */ foreach ($relatedSet as $nextRow) {
	// $nameField = $nextRow->getField(’customer::name’) if ($nameField == $badName ) {
	// 	$result =   $newRow->delete();
	// }

	/**
	 * Renvoie la liste des lieux
	 * @return array
	 */
	public function getRapportsLieux() {
		if($this->isUserLogged() === true) {
			// $vals = array(0 => "0", 1 => "1");
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->FMbase->newFindAllCommand('Lieu_Liste');
			$this->FMfind->addSortRule('cle', 1, FILEMAKER_SORT_DESCEND);
			$result = $this->FMfind->execute();
			return $this->getRecords($result);
		} else {
			return "Utilisateur non connecté.";
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
			$this->FMfind =& $this->FMbase->newFindAllCommand('Locaux_IPAD');
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
			$this->FMfind =& $this->FMbase->newFindAllCommand('Projet_liste');
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
			$this->FMfind =& $this->FMbase->newFindAllCommand('Tiers_Liste');
			$this->FMfind->addSortRule('ref', 1, FILEMAKER_SORT_DESCEND);
			return $this->getRecords($this->FMfind->execute());
		} else {
			$records = "Utilisateur non connecté.";
			return $records;
		}
	}

	// Informations de structure de base de données


	// /**
	//  * Renvoie la liste des bases de données via SAdmin
	//  * @return array
	//  */
	// public function getDatabasesSAdmin() {
	// 	if($this->isSadminLogged() === true) {
	// 		// Create FileMaker_Command_Find on layout to search
	// 		$this->FMbase->setProperty('hostspec', 'http://localhost');
	// 		$this->FMfind = $this->FMbase->listDatabases();
	// 		// $result = $this->FMfind->execute();
	// 		if ($this->FMbase->isError($this->FMfind)) {
	// 			$records = "Accès non autorisé.";
	// 		} else {
	// 			$records = $this->FMfind;
	// 		}
	// 	} else {
	// 		$records = "Super Admin non connecté.";
	// 	}
	// 	return $records;
	// }

	/**
	 * Renvoie la liste des scripts
 	 * @param string $SERVnom - nom du serveur (ou serveur par défaut si null)
	 * @param string $BASEnom - nom de la base (ou base par défaut si null)
	 * @return array
	 */
	public function getScripts($SERVnom = null, $BASEnom = null) {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			// $this->FMbase->setProperty('hostspec', 'http://localhost');
			$this->FMfind = $this->FMbase->listScripts();
			// $result = $this->FMfind->execute();
			if ($this->FMbase->isError($this->FMfind)) {
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
		// $this->FMbase->setProperty('hostspec', 'http://localhost');
		if($this->isSadminLogged() === true) {
			$this->log_sadmin($BDD);
			$this->FMfind = $this->FMbase->listScripts();
			// $result = $this->FMbase->execute();
			if ($this->FMbase->isError($this->FMfind)) {
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
	public function getLayouts($SERVnom = null, $BASEnom = null) {
		$this->FMbase = new \FileMaker();
		$this->FMbase->setProperty('database', $this->getCurrentBASE());
		$this->FMbase->setProperty('hostspec', $this->getCurrentIP());
		$this->FMbase->setProperty('username', $this->getCurrentSAdminLogin());
		$this->FMbase->setProperty('password', $this->getCurrentSAdminPasse());
		$this->FMfind = $this->FMbase->listLayouts();
		if ($this->FMbase->isError($this->FMfind)) {
		    $records = "Accès non autorisé.";
		} else {
			$records = $this->FMfind;
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
			$this->FMbase = new \FileMaker();
			$this->FMbase->setProperty('database', "GEODIAG_Rapports");
			$this->FMbase->setProperty('hostspec', 'http://localhost');
			$this->FMbase->setProperty('username', "Sadmin");
			$this->FMbase->setProperty('password', "Symfony76");
			$this->FMfind = $this->FMbase->listLayouts();
			// $result = $this->FMfind->execute();
			if ($this->FMbase->isError($this->FMfind)) {
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
		return $this->FMbase->getAPIVersion();
	}




	// ***********************
	// ERRORS & DEV
	// ***********************

	/**
	 * Ajoute une erreur / insère la date/heure automatiquement
	 * @param array/string
	 * @return filemakerservice
	 */
	protected function addError($message) {
		return $this->addErrors($message);
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
			// ajoute un point à la fin du message s'il n'y en a pas
			if($message[strlen($message) - 1] !== ".") $message = $message.".";
			// et une majuscule en début de ligne…
			$message = ucfirst($message);
			$this->globalErrors[] = array($message, $time);
			if($putFlash === true) {
				$this->container->get("session")->getFlashBag()->add("FMerror", $message." (".$time->format("H:i:s - d/m/Y").")");
			}
		}
		return $this;
	}

	/**
	 * Renvoie la liste des erreurs
	 * @return array globalErrors
	 */
	public function getErrors() {
		return $this->globalErrors;
	}

	/**
	 * Affiche les erreurs (Boostrap required)
	 */
	protected function affErrors() {
		if($this->DEV === true) {
			echo("<br /><br /><div class='container'><table class='table table-bordered table-hover table-condensed'>");
			foreach ($this->globalErrors as $key => $error) {
				echo("	<tr>");
				echo("		<td>".$error[0]."</td>");
				echo("		<td>".$error[1]->format("H:i:s - Y/m/d")."</td>");
				echo("	</tr>");
			}
			echo("</table></div><br />");
		}
	}

	/**
	 * DEV : affiche $data
	 */
	protected function vardumpDev($data, $titre = null) {
		if($this->DEV === true) {
			if($titre !== null && is_string($titre) && strlen($titre) > 0) {
				echo('<h2>'.$titre.'</h2>');
			}
			echo("<pre>");
			var_dump($data);
			echo("</pre>");
		}
	}

	/**
	 * DEV : affiche $texte
	 */
	protected function echoDev($texte, $end = "<br>") {
		if($this->DEV === true) {
			echo(ucfirst($texte.$end));
		}
	}



}