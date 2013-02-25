<?php

/**
 * a "lite" MVC-ish Controller
 *
 * @class Ugs
 * @namespace ugsPhp
 */
class Ugs{

	/**
	 * Boostraps and runs the entire danged system!
	 */
	function __construct() {
		$this->Bootstrap();

		// Reads query param to pick appropriate Actions
		$action = isset($_GET['action']) ? Actions::ToEnum($_GET['action']) : Actions::SongList;

		$user = $this->DoAuthenticate( $action );
		if ( !$user->IsAllowAccess  ) {
				return;
			}

		$builder = $this->GetBuilder( $action, $user );
		$model = $builder->Build();

		// all done, time to render
		if ( $model->IsJson ) {
			$this->RenderJson( $model );
		}
		else {
			$model->SiteUser = $user;
			$this->RenderView( $model, $action );
		}
	}

	/**
	 * Renders View associated with Action, making only $model available on the page
	 *
	 * @param [ViewModel] $model  appropriate view model entity
	 * @param [Actions(int)] $action
	 */
	private function RenderView( $model, $action ) {
		header('X-Powered-By: ' . Config::PoweredBy);
		include_once Config::$ViewsPath . $this->GetViewName( $action );
	}


	/**
	 * Emits serilized JSON version of the $model with appropriate headers
	 *
	 * @param unknown $model
	 */
	private function RenderJson( $model ) {
		header( 'Content-Type: application/json' );
		unset($model->IsJson);
		echo json_encode( $model );
	}

	/**
	 * returns initialized SiteUser object, check the "Is Allow Access" property.
	 * This method MAY hijack flow controlby performing a recirect
	 * or by rendering an alternate view
	 *
	 * @param Actions(enum) $action
	 * @return SiteUser
	 */
	private function DoAuthenticate( $action ) {

		if (! Config::IsLoginRequired ) {
			$user = new SiteUser();
			$user->IsAllowAccess = true;
			return  $user;
		}

		$login = new SimpleLogin();

		if ($action == Actions::Logout){
			$login->Logout();
			header('Location: ' . self::MakeUri(Actions::Login));
			return  $login->GetUser();
		}

		$user = $login->GetUser();
		if ( !$user->IsAllowAccess ) {
			$builder = $this->GetBuilder( Actions::Login, $user );
			$model = $builder->Build($login);
			$user = $login->GetUser();

			// during form post the builder automatically attempts a login -- let's check whether that succeeded...
			if ( !$user->IsAllowAccess ) {
				$this->RenderView( $model, Actions::Login );
				return  $user;
			}

			// successful login we redirect:
			header('Location: ' . self::MakeUri(Actions::SongList));
			return  $user;
		}
		elseif ($action == Actions::Login){
			// if for some reason visitor is already logged in but attempting to view the Login page, redirect:
			header('Location: ' . self::MakeUri(Actions::SongList));
			return $user;
		}

		// $user->IsAllowAccess = true;
		return $user;
	}

	/**
	 * Returns instance of appropriate Builder class
	 *
	 * @param ActionEnum $action desired action
	 * @param SiteUser $siteUser current visitor
	 * @return ViewModelBuilder-Object (Instantiated class)
	 */
	private function GetBuilder( $action, $siteUser ) {
		$builder = null;

		switch($action){
			case Actions::Edit:
			case Actions::Song:
				$builder = new Song_Vmb();
				break;
			case Actions::Source:
				$builder = new Source_Vmb();
				break;
			case Actions::Reindex:
				$builder = new RebuildSongCache_Vmb();
				break;
			case Actions::Logout:
			case Actions::Login:
				$builder = new Login_Vmb();
				break;
		case Actions::AjaxNewSong:
			$builder = new Ajax_NewSong_Vmb();
			break;
		case Actions::AjaxUpdateSong:
			$builder = new Ajax_UpdateSong_Vmb();
			break;
			default:
				$builder = Config::UseDetailedLists
					? new SongListDetailed_Vmb()
					: new SongList_Vmb();
				break;
		}

		$builder->SiteUser = $siteUser;
		return $builder;
	}

	/**
	 * Bootstraps UGS...
	 * > Instantiates configs class
	 * > Automatically includes ALL of the PHP classes in these directories: "classes" and "viewmodels".
	 * This is a naive approach, see not about including base classes first.
	 *
	 * @private
	 */
	private function Bootstrap() {
		// let's get Config setup
		$appRoot = dirname(__FILE__);
		include_once $appRoot . '/Config.php';

		// some dependencies: make sure base classes are included first...
		include_once $appRoot . '/classes/SiteUser.php';
		include_once $appRoot . '/viewmodels/_base_Vm.php';
		include_once $appRoot . '/builders/_base_Vmb.php';

		Config::Init();

		foreach (array('classes', 'viewmodels', 'builders') as $directory) {
			foreach (glob($appRoot . '/' . $directory . '/*.php') as $filename) {
				include_once $filename;
			}
		}

	}

	/**
	 * builds (relative) URL
	 *
	 * @param Actions(enum) $action [description]
	 * @param string  $param  (optional) extra query param value (right now only "song")
	 * @return  string
	 */
	public static function MakeUri($action, $param = ''){
		$actionName = Actions::ToName($action);
		$param = trim($param);

		if (!Config::UseModRewrite){
			$actionParams = strlen($param) > 0 ? '&song=' . $param : '';
			return '/music.php?action=' . $actionName . $actionParams;
		}

		if (($action == Actions::Song) || ($action == Actions::SongList)) {
			$actionName = 'songbook';
		}
		return '/' . strtolower($actionName) . '/' . $param;
	}

	public function GetJsonObject(){
		$input = @file_get_contents('php://input');
		$response = json_decode($input);
		return $response;
	}

	public static function GetParam($name){
		return  trim(isset($_POST[$name]) ? $_POST[$name] : '');
	}

	/**
	 * Gets the PHP filename (aka "View") to be rendered
	 *
	 * @param Actions(int-enum) $action
	 * @return  string
	 */
	private function GetViewName( $action ) {
		switch($action){
			case Actions::Song: return Config::UseEditableSong ? 'song-editable.php' : 'song.php';
			case Actions::Edit: return 'song-editable.php';
			case Actions::Source: return 'song-source.php';
			case Actions::Reindex: return 'songs-rebuild-cache.php';
			case Actions::Logout:
			case Actions::Login:
				return 'login.php';
		}
		return Config::UseDetailedLists ? 'song-list-detailed.php' : 'song-list.php';
	}


}
