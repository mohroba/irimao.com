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
            'course_code' => 'کد دوره',
            'course_type' => 'نوع دوره',
            'level'       => 'سطح دوره',
            'board'       => 'هیئت',
            'attendance'  => 'نوع حضور',
            'gender'      => 'جنسیت دوره',
        ];
    }
}
