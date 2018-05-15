<?php
namespace Application\Block\TematikManualNav;

use Core;
use Page;
use Log;
use Less_Parser;
use Less_Tree_Rule;
use Concrete\Core\Block\BlockController;

defined('C5_EXECUTE') or die("Access Denied.");
/*
Correctif:
-Unique bouton de création d'objet
-Type de lien externe
-Gestion des erreurs (titre obligatoire, url correcte obligatoire)
*/
class Controller extends BlockController
{
    protected $btTable = 'btWhaleManualNavPixel';
    protected $btInterfaceWidth = 700;
    protected $btInterfaceHeight = 550;
    protected $btCacheBlockOutput = true;
    protected $btCacheBlockOutputOnPost = true;
    protected $btCacheBlockOutputForRegisteredUsers = true;
    protected $btExportPageColumns = array('internalLinkCID');
    protected $btDefaultSet = 'navigation';

    protected $maxDepth = 5; //nestable max allowed depth
    
    protected $nav = array();

    protected $level;
    protected $cIDCurrent;
    protected $selectedPathCIDs;

    //Description du block
    public function getBlockTypeDescription()
    {
        return t("Navigation Manuelle");
    }

    //Nom du block
    public function getBlockTypeName()
    {
        return t("Navigation Manuelle Nestable");
    }

    //Fonction d'ajout du block: lance la fonction edit
    public function add()
    {
        $this->edit();
    }

    //Fonction d'édition du block
    //On utilise l'asset core/sitemap
    //On met à jour les variables
    //on utilise les assets css et font-awesome
    public function edit()
    {
        $this->requireAsset('core/sitemap');
        $this->setVariables();
        $this->requireAsset('css', 'font-awesome');
        $classes = $this->getIconClasses();

        // nettoyage
        $icons = array('' => t('Choose Icon'));
        $txt = Core::make('helper/text');
        foreach ($classes as $class) {
            $icons[$class] = $txt->unhandle($class);
        }
        $this->set('icons', $icons);
    }

    // fonction getter des icones
    protected function getIconClasses()
    {
        $iconLessFile = DIR_BASE_CORE . '/css/build/vendor/font-awesome/variables.less';
        $icons = array();

        $l = new Less_Parser();
        $parser = $l->parseFile($iconLessFile, false, true);
        $rules = $parser->rules;

        foreach ($rules as $rule) {
            if ($rule instanceof Less_Tree_Rule) {
                if (strpos($rule->name, '@fa-var') === 0) {
                    $name = str_replace('@fa-var-', '', $rule->name);
                    $icons[] = $name;
                }
            }
        }
        asort($icons);

        return $icons;
    }

    //Fonction pour set les variables utilisées dans la vue
    private function setVariables()
    {
        $jh = Core::make('helper/json');
        $navItemsAr = ($this->navItems) ? $jh->decode($this->navItems) : array();
        if(!is_array($navItemsAr)) $navItemsAr = array();

        //On réindexe les ids
        $navItemsAr = $this->reindexNavItems($navItemsAr);

        $this->set('navItemsAr', $navItemsAr );
        $this->set('maxDepth', $this->maxDepth );
    }   

    //Fonction pour réindéxer les ids
    private function reindexNavItems($navItemsAr, &$i=0)
    {
        foreach ($navItemsAr as $key => $item) {
            $i++;
            $navItemsAr[$key]->id = $i;
            if (isset($item->children) && is_array($item->children) && count($item->children)>0){
                $this->reindexNavItems($item->children, $i);
            }
        }
        return $navItemsAr;
    }   

    //Fonction pour obtenir les attributs(informations) des objets (items)
    private function getNavItemInfo($item)
    {
        $nh = Core::make('helper/navigation');

        $navItem = new \stdClass();

        $navItem->name = isset($item->itemName) ? $item->itemName : '*';
        
        //On initialise les attributs
        $navItem->itemUrlType = $item->itemUrlType;
        $navItem->cObj = false;
        $navItem->cID = false;
        $navItem->url = '#';
        $navItem->icone = '';
        $navItem->ccss = '';
        $navItem->isHome = false;
        $navItem->isCurrent = false;
        $navItem->inPath = false;
        $navItem->attrClass = '';
        //Si le type de l'URL est normal, alors $navItem->url vaut l'URL externe, sinon $navItem->url vaut l'URL interne
        if ($item->itemUrlType == 'external'){
            $navItem->url = $item->itemUrlExternal;
            $navItem->icone = $item->itemIcon;
            $navItem->ccss = $item->itemClasseCss;
        } elseif ($item->itemUrlType == 'internal') {
            $page = Page::getByID((int)$item->itemUrlInternal);
            if (isset($page->cID)) {
                $navItem->cObj = $page;
                $navItem->cID = $page->cID;
                $navItem->url = $nh->getCollectionURL($page);

                if ($page->getAttribute('replace_link_with_first_in_nav')) {
                    $subPage = $page->getFirstChild();
                    if ($subPage instanceof Page) {
                        $pageLink = $nh->getLinkToCollection($subPage);
                        if ($pageLink) $navItem->url = $pageLink;
                    }
                }

                if ($page->cID == HOME_CID) $navItem->isHome = true;
                if ($page->cID == $this->cIDCurrent) {
                    $navItem->isCurrent = true;
                    $navItem->inPath = true;
                } elseif (in_array($page->cID, $this->selectedPathCIDs)) {
                    $navItem->inPath = true;
                }   
                $attribute_class = $page->getAttribute('nav_item_class');
                if (!empty($attribute_class)) $navItem->attrClass = $attribute_class;
            }    
        }
        //Nouvelle fenetre
        $navItem->target = isset($item->itemUrlNewWindow) ? $item->itemUrlNewWindow == 1 ? '_blank' : '_self' : '_self';

        $navItem->level = $this->level;

        $this->nav[] = $navItem;

        //Gestion des sous-menus
        $navItem->hasSubmenu = false;
        if (isset($item->children) && count($item->children)>0) {
            $navItem->hasSubmenu = true;
            $this->level++;
            foreach ($item->children as $key => $item) {
                $this->getNavItemInfo($item);   
            }
            $this->level--;
            
        }
    }

    

    //Fonction qui retourne un array(nav)
    public function getNavItems()
    {
        $jh = Core::make('helper/json');
        
        $this->level = 1;
        $this->cIDCurrent = Page::getCurrentPage()->getCollectionID();
        $this->selectedPathCIDs = array($this->cIDCurrent);
        
        //Stockage de l'id des parents
        $parentCIDnotZero = true;
        $inspectC = Page::getCurrentPage();
        $homePageID = $inspectC->getSiteHomePageID();
        while ($parentCIDnotZero) {
            $cParentID = $inspectC->getCollectionParentID();
            if (!intval($cParentID)) {
                $parentCIDnotZero = false;
            } else {
                if ($cParentID != $homePageID) {
                    $this->selectedPathCIDs[] = $cParentID; //Ne veut pas une page d'accueil dans le nav-path-selected
                }
                $inspectC = Page::getById($cParentID, 'ACTIVE');
            }
        }
        
        //Prépare toutes les données et les place dans une structure propre pour que le balisage de sortie soit le plus simple possible
        $navItemsAr = ($this->navItems) ? $jh->decode($this->navItems) : array();
        if(!is_array($navItemsAr)) $navItemsAr = array();
        
        //get chaque info de l'item      
        foreach ($navItemsAr as $key => $item) {
            $this->getNavItemInfo($item);
        }

        //Ajoute une extra info à chaque item
        for ($i = 0; $i < count($this->nav); $i++) {

            $current_level = $this->nav[$i]->level; 
            $prev_level = isset($this->nav[$i - 1]) ? $this->nav[$i - 1]->level : -1;
            $next_level = isset($this->nav[$i + 1]) ? $this->nav[$i + 1]->level : 1;
            
            //Calcule la différence entre le niveau de cet item pour connaître le nombre de balise fermante pour sortir de la balise
            $this->nav[$i]->subDepth = $current_level - $next_level; //echo $current_level."-".$next_level."-".$this->nav[$i]->subDepth."<br>";
            //Calcule si c'est le premier item de son niveau (utile pour les classes de CSS)
            $this->nav[$i]->isFirst = $current_level > $prev_level;
            //Calcule si c'est le dernier item de son niveau (utile pour les classes de CSS)
            $this->nav[$i]->isLast = true;
            for ($j = $i + 1; $j < count($this->nav); ++$j) {
                if ($this->nav[$j]->level == $current_level) {
                    //on a trouvé un item subséquent à ce niveau (avant que ce niveau ait terminé), donc ce n'est pas le dernier de son niveau.
                    $this->nav[$i]->isLast = false;
                    break;
                }
                if ($this->nav[$j]->level < $current_level) {
                    //on a trouvé un niveau précédent avant chaque item dans ce niveau, donc c'est le dernier de son niveau)
                    $this->nav[$i]->isLast = true;
                    break;
                }
            } //Si la boucle se termine avant une des conditions "if", alors il s'agit du dernier dans son level (et $is_last_in_level reste vrai)

        }
            
        return $this->nav;
    }    


}
