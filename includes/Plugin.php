<?php

namespace IMAOCustom;

use IMAOCustom\Services\Assets;
use IMAOCustom\Services\Registration;
use IMAOCustom\Services\SelfDeclarations;
use IMAOCustom\Services\ClubApplications;
use IMAOCustom\Services\Courses;
use IMAOCustom\Services\Endpoints\EditBasicInfo;
use IMAOCustom\Services\Endpoints\IdentityProfessional;
use IMAOCustom\Services\Endpoints\ChangePassword;
use IMAOCustom\Services\Endpoints\Wallet;
use IMAOCustom\Services\Admin\UserManagement;
use IMAOCustom\Services\Endpoints\SmartcardIssue;
use IMAOCustom\Services\Endpoints\SelfDeclaration;
use IMAOCustom\Services\Endpoints\SelfDeclarationsList;
use IMAOCustom\Services\Endpoints\ClubRegister;
use IMAOCustom\Services\Competitions;
use IMAOCustom\Services\Ranking;
use IMAOCustom\Services\Endpoints\CompetitionsList;
use IMAOCustom\Services\Endpoints\CompetitionDetails;
use IMAOCustom\Services\Endpoints\UserCompetitionsList;
use IMAOCustom\Services\Endpoints\CourseList;
use IMAOCustom\Services\Endpoints\UserCourseList;
use IMAOCustom\Services\Endpoints\CourseDetails;
use IMAOCustom\Services\Endpoints\StyleCommittee;
use IMAOCustom\Services\Avatar;
use IMAOCustom\Services\SecurityHeaders;
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
        EditBasicInfo::class,
        IdentityProfessional::class,
        ChangePassword::class,
        Wallet::class,
        UserManagement::class,
        SmartcardIssue::class,
        SelfDeclaration::class,
        SelfDeclarationsList::class,
        ClubRegister::class,
        Competitions::class,
        CompetitionsList::class,
        CompetitionDetails::class,
        UserCompetitionsList::class,
        Ranking::class,
        CourseList::class,
        UserCourseList::class,
        CourseDetails::class,
        StyleCommittee::class,
        Avatar::class,
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
}

