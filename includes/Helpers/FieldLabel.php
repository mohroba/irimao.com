<?php
namespace IMAOCustom\Helpers;

class FieldLabel {
    public static function get(string $key, $value): string {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        switch ($key) {
            case 'gender':
                $map = [
                    'male'   => 'مرد',
                    'female' => 'زن',
                ];
                return $map[$value] ?? $value;
            case 'marital_status':
                $map = [
                    'single'  => 'مجرد',
                    'married' => 'متأهل',
                ];
                return $map[$value] ?? $value;
            case 'education_status':
                $map = [
                    'student_primary'    => 'محصل - ابتدایی',
                    'student_highschool' => 'محصل - دبیرستان',
                    'student_college'    => 'دانشجوی کاردانی',
                    'bachelor'           => 'کارشناسی',
                    'master'             => 'کارشناسی ارشد',
                    'phd'                => 'دکتری و بالاتر',
                ];
                return $map[$value] ?? $value;
            case 'military_status':
                $map = [
                    'completed'          => 'پایان خدمت',
                    'exempt'             => 'معافیت',
                    'exempt_medical'     => 'معافیت پزشکی',
                    'exempt_non_medical' => 'معافیت غیر پزشکی',
                    'exempt_leadership'  => 'معافیت رهبری',
                    'not_performed'      => 'انجام نداده',
                    'studying'           => 'اشتغال به تحصیل',
                    'seminarian'         => 'اشتغال به خدمت – طلبه',
                ];
                return $map[$value] ?? $value;
            case 'birth_province':
            case 'residence_province':
                $provinces = CityMap::get_provinces();
                return $provinces[$value] ?? $value;
        }
        return $value;
    }
}
