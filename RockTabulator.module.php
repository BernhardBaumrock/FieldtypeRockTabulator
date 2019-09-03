<?php namespace ProcessWire;
/**
 * RockTabulator
 *
 * @author Bernhard Baumrock, 15.07.2019
 * @license Licensed under MIT
 */
class RockTabulator extends RockMarkup2 {
  public static function getModuleInfo() {
    return [
      'title' => 'RockTabulator Main Module',
      'version' => '0.0.2',
      'summary' => 'RockTabulator Main Module that installs and uninstalls all related modules.',
      'singular' => true,
      'autoload' => true,
      'icon' => 'bolt',
      'requires' => ['RockMarkup2'],
      'installs' => [
        'FieldtypeRockTabulator',
        'InputfieldRockTabulator',
        'ProcessRockTabulator',
      ],
    ];
  }
  static protected $defaults = array(
    'langs' => "default=en-gb\nde=de-de",
  );
  public function getModuleConfigInputfields(array $data) {
    $inputfields = parent::getModuleConfigInputfields($data);
    $data = array_merge(parent::$defaults, self::$defaults, $data);

    $f = $this->modules->get('InputfieldTextarea');
    $f->name = 'langs';
    $f->label = $this->_('Language Mappings');
    $f->description = $this->_('List all languages of your system with their mapped locale for RockTabulator here.');
    $f->notes = $this->_('One by line, eg de=de-de (where de is the language name and de-de is the tabulator locale');
    $f->value = $data['langs'];
    $inputfields->add($f);

    return $inputfields;
  }

  /**
   * RockTabulator JS config
   * @var WireArray
   */
  public $conf;

  public function __construct() {
    // populate defaults, which will get replaced with actual
    // configured values before the init/ready methods are called
    $this->setArray(array_merge(parent::$defaults, self::$defaults));
  }

  /**
   * Init module
   */
  public function init() {
    parent::init();
    
    // intercept 404 page for returning ajax data
    require_once('RockTabulatorGrid.php');
    $this->addHookBefore('ProcessPageView::pageNotFound', $this, 'handleAjax');
  }

  
  public function ready() {
    // load locales
    $this->conf = $this->wire(new WireArray);
    $this->addHookBefore("setGlobalConfig", $this, 'loadLocales');
    $this->setGlobalConfig();
  }

  /**
   * Load locales to config object
   */
  public function loadLocales($event) {
    $locale = $this->getLocale();
    if(!$locale) return;

    $data = $this->wire->files->render(__DIR__ . '/_langs.php');
    $this->conf->set('locale', $locale);
    $this->conf->set('langs', [$locale => $data]);
  }
  
  /**
   * Get tabulator locale string of current pw user's language
   *
   * @return string
   */
  public function getLocale() {
    foreach(explode("\n", $this->langs) as $item) {
      $item = explode("=", $item);
      $name = $item[0];
      $locale = $item[1];
      if(!$this->user->language) return;
      if($name == $this->user->language->name) return $locale;
    }
  }

  /**
   * Set global JS configuration object
   * 
   */
  public function ___setGlobalConfig() {
    $data = $this->conf->getArray();
    $this->wire->config->js('RockTabulator', $data);
  }

  /**
   * Handle AJAX request
   */
  public function handleAjax($event) {
    if(!$this->config->ajax) return;

    // check name property
    $name = $this->input->post('name', 'string');
    if(!$name) return;

    $url = $event->arguments(1);
    if($url != '/rocktabulator/') return;

    // ########## GET DATA ##########
    $langID = $langID = $this->input->post('lang', 'int');
    if($langID) {
      $lang = $this->languages->get($langID);
      $this->user->language = $lang;
    }

    // handle rowaction calls before getting data of the grid
    $this->handleRowaction($name);

    // do not execute the 404, return gzipped json data instead
    try {
      /** @var RockTabulatorGrid $grid */
      $grid = $this->getGrid($name);
      $data = $grid->getJsonObject();
    } catch (\Throwable $th) {
      $data = $this->err($th->getMessage());
    }
    $this->gzip($data);
  }

  /**
   * Handle call of rowactions
   * @param string $grid name of grid
   * @return bool
   */
  public function handleRowaction($grid) {
    $name = $this->input->get('rowaction', 'string');
    if(!$name) return;

    try {
      $grid = $this->getGrid($grid, false);
      $action = $grid->getRowaction($name);
      if($action) {
        // check access
        if(!$action->access()) {
          throw new WireException("You are not allowed to execute this rowaction");
        }

        // this makes sure that notices don't break the ajax response
        // when errors are reported via PHP they are added before all other
        // output which makes the gzipped response corrupt
        error_reporting(0);
        $data = $action->execute();
        if($data) $data = ['success' => $data];
      }
      else throw new WireException("Action $name not found");
    } catch (\Throwable $th) {
      $data = $this->err($th->getMessage());
    }
    
    $this->gzip($data);
  }

  /**
   * Return JSON error
   * @param string $msg
   * @return json
   */
  public function err($msg) {
    return (object)[
      'error' => $msg
    ];
  }

  /**
   * Return gzip data and exit
   * @param mixed $data
   * @return void
   */
  public function gzip($data) {
    header("Content-type: application/json");
    ob_start("ob_gzhandler");
    echo json_encode($data);
    ob_end_flush();
    exit();
  }

  /**
   * Get grid data object
   * 
   * If loadRows flag is set to TRUE it will return the data object without
   * loading all rows of the tabulator. This is necessary if one only wants
   * to retrieve rowactions or gridactions but does not need all data rows.
   * 
   * @param string $name name of grid
   * @param bool $loadRows load row data?
   * @return mixed
   */
  public function getGrid($name = null, $loadRows = true) {
    if(!$name) $name = $this->input->get('name', 'string');
    if(!$name) return;

    // set loadRows flag in session
    $this->session->loadTabulatorRows = $loadRows;

    // get file and load data
    $file = $this->getFile($name);
    $grid = $this->files->render($file->path, [], [
      'allowedPaths' => [$file->dir],
    ]);

    // if the php file does not return a grid we exit here
    if(!$grid instanceof RockTabulatorGrid) return;

    // set gridname
    $grid->name = $name;

    // ########## CHECK ACCESS ##########
    // by default only superusers have access
    $access = false;
    $msg = 'NO ACCESS';
    try {
      $access = $grid->access();
    } catch (\Throwable $th) {
      $msg = $th->getMessage();
      $access = false;
    }
    if(!$access) throw new WireException($msg);

    // data correct?
    if(!$grid) return;
    if(!$grid instanceof RockTabulatorGrid) {
      throw new WireException("{$file->url} must return a RockTabulatorGrid object!");
    }

    // all good, return data
    return $grid;
  }
  
  /**
   * Get example PHP code for main PHP file
   */
  public function getPhpCode() {
    return file_get_contents(__DIR__ . '/snippets/php.php');
  }
}
