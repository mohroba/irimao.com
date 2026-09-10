<?php

namespace IMAOCustom;

use IMAOCustom\Services\Assets;
use IMAOCustom\Services\Registration;
use IMAOCustom\Services\SelfDeclarations;
use IMAOCustom\Services\ClubApplications;
use IMAOCustom\Services\Courses;
use IMAOCustom\Services\AgeCategories;
use IMAOCustom\Services\AgeCategoryWeightSeeder;
use IMAOCustom\Services\Endpoints\EditBasicInfo;
use IMAOCustom\Services\Endpoints\IdentityProfessional;
use IMAOCustom\Services\Endpoints\ChangePassword;
use IMAOCustom\Services\Endpoints\Wallet;
use IMAOCustom\Services\Admin\UserManagement;
use IMAOCustom\Services\Admin\Settings;
use IMAOCustom\Services\Admin\DataGrid;
use IMAOCustom\Services\Admin\DataTransfer;
use IMAOCustom\Services\Endpoints\SmartcardIssue;
use IMAOCustom\Services\Endpoints\SelfDeclaration;
use IMAOCustom\Services\Endpoints\SelfDeclarationsList;
use IMAOCustom\Services\Endpoints\ClubRegister;
use IMAOCustom\Services\Endpoints\ClubStudents;
use IMAOCustom\Services\Endpoints\CoachStudents;
use IMAOCustom\Services\Competitions;
use IMAOCustom\Services\Ranking;
use IMAOCustom\Services\Endpoints\CompetitionsList;
use IMAOCustom\Services\Endpoints\CompetitionDetails;
use IMAOCustom\Services\Endpoints\UserCompetitionList;
use IMAOCustom\Services\Endpoints\CourseList;
use IMAOCustom\Services\Endpoints\UserCourseList;
use IMAOCustom\Services\Endpoints\CourseDetails;
use IMAOCustom\Services\Endpoints\ApprovedLists;
use IMAOCustom\Services\Endpoints\StyleCommittee;
use IMAOCustom\Services\Endpoints\StyleCommitteeFormEndpoint;
use IMAOCustom\Services\Avatar;
use IMAOCustom\Services\SecurityHeaders;
use IMAOCustom\Services\Eligibility;
use IMAOCustom\Services\Ban;
use IMAOCustom\Services\LoginTracking;
use IMAOCustom\Services\RoleCleanup;
use IMAOCustom\Services\StyleCommitteeRequests;
use IMAOCustom\Services\CheckoutPrefill;
use IMAOCustom\Services\OrderStatus;
use IMAOCustom\Services\ProvinceRepresentatives;
use IMAOCustom\Services\PayoutManager;
use IMAOCustom\Services\CompetitionCards;
use IMAOCustom\Services\CompetitionBrackets;
use IMAOCustom\ServiceManager;



class Plugin {
    private static ?Plugin $instance = null;

    /** @var array<int,object> */
    private array $services = [];

    /** @var array<int,string> */
    private array $service_classes = [
        Assets::class,
        Registration::class,
        SelfDeclarations::class,
        ClubApplications::class,
        Courses::class,
        AgeCategories::class,
        AgeCategoryWeightSeeder::class,
        EditBasicInfo::class,
        IdentityProfessional::class,
        ChangePassword::class,
        Wallet::class,
        PayoutManager::class,
        CheckoutPrefill::class,
        OrderStatus::class,
        UserManagement::class,
        Settings::class,
        DataGrid::class,
        DataTransfer::class,
        SmartcardIssue::class,
        SelfDeclaration::class,
        SelfDeclarationsList::class,
        ClubRegister::class,
        ClubStudents::class,
        CoachStudents::class,
        Competitions::class,
        CompetitionCards::class,
        CompetitionBrackets::class,
        CompetitionsList::class,
        CompetitionDetails::class,
        UserCompetitionList::class,
        Ranking::class,
        CourseList::class,
        UserCourseList::class,
        CourseDetails::class,
        ApprovedLists::class,
        StyleCommittee::class,
        StyleCommitteeFormEndpoint::class,
        StyleCommitteeRequests::class,
        Avatar::class,
        Eligibility::class,
        Ban::class,
        LoginTracking::class,
        RoleCleanup::class,
        ProvinceRepresentatives::class,
    ];

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        $this->register_services();
    }

    public static function get_instance(): Plugin {
        return self::$instance ??= new self();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'imao-custom-plugin', false, dirname( plugin_basename( __FILE__ ), 2 ) . '/languages' );
    }

    private function register_services(): void {
        $manager        = new ServiceManager( $this->service_classes );
        $this->services = $manager->register_all();
    }

    /**
     * Load payout role configuration.
     *
     * @return array<string,array{label:string,resolver:string,meta_key?:string,scope?:string}>
     */
    public static function get_payout_roles(): array {
        static $roles = null;
        if ( $roles === null ) {
            $file  = __DIR__ . '/Config/PayoutRoles.php';
            $roles = is_file( $file ) ? require $file : [];
        }
        return $roles;
    }
}
