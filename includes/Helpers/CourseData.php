<?php

namespace IMAOCustom\Helpers;

class CourseData {
    /**
     * Return columns for course list tables.
     *
     * @return array<string,string>
     */
    public static function columns(): array {
        return [
            'course_code'  => 'کد دوره',
            'course_type'  => 'نوع دوره',
            'course_level' => 'درجه/زیرشاخه',
            'board'        => 'هیئت',
            'style'        => 'سبک',
            'scope'        => 'نوع',
            'gender'       => 'جنسیت',
        ];
    }
}
