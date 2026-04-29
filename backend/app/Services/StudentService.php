<?php

namespace App\Services;

use App\Models\Student;

class StudentService
{
    /**
     * Get unique skills across all students for filter metadata.
     */
    public function getAvailableSkills(): array
    {
        // Fetch only the specific columns needed to avoid loading full models into memory
        $technical = \Illuminate\Support\Facades\DB::table('students')
            ->whereNotNull('technical_skills')
            ->pluck('technical_skills')
            ->map(fn($item) => json_decode($item, true))
            ->flatten()
            ->unique()
            ->values()
            ->toArray();

        $other = \Illuminate\Support\Facades\DB::table('students')
            ->whereNotNull('other_skills')
            ->pluck('other_skills')
            ->map(fn($item) => json_decode($item, true))
            ->flatten()
            ->unique()
            ->values()
            ->toArray();
        
        return array_values(array_unique(array_merge($technical, $other)));
    }

    /**
     * Get students for the listing/search view, applying powerful multi-criteria filters.
     */
    public function getAllStudents(array $filters = [])
    {
        $query = Student::with([
            'academicHistories',
            'sportsSkills',
            'affiliations',
            'violations',
            'activities'
        ]);

        // 1. Skill Filtering (Supports Similarity Matching)
        $skillFilter = $filters['skill'] ?? $filters['skills'] ?? null;
        if (!empty($skillFilter)) {
            $skillTerms = is_array($skillFilter) ? $skillFilter : [trim($skillFilter)];
            
            // Expand terms with similarity matching if requested or by default
            $expandedTerms = $skillTerms;
            $allAvailable = $this->getAvailableSkills();
            
            foreach ($skillTerms as $term) {
                $lowerTerm = strtolower($term);
                foreach ($allAvailable as $available) {
                    $lowerAvailable = strtolower($available);
                    // If distance is small (1 or 2) and they aren't already included
                    if (levenshtein($lowerTerm, $lowerAvailable) <= 2 && !in_array($available, $expandedTerms)) {
                        $expandedTerms[] = $available;
                    }
                }
            }

            $query->where(function($q) use ($expandedTerms) {
                $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
                foreach ($expandedTerms as $term) {
                    if ($driver === 'pgsql') {
                        $q->orWhereRaw('CAST(technical_skills AS TEXT) ILIKE ?', ["%{$term}%"])
                          ->orWhereRaw('CAST(other_skills AS TEXT) ILIKE ?', ["%{$term}%"]);
                    } else {
                        $q->orWhereRaw('LOWER(technical_skills) LIKE ?', ["%".strtolower($term)."%"])
                          ->orWhereRaw('LOWER(other_skills) LIKE ?', ["%".strtolower($term)."%"]);
                    }
                }
            });
        }

        // 2. GPA Filter
        if (isset($filters['minGPA'])) {
            $query->where('gpa', '>=', (float)$filters['minGPA']);
        }
        if (isset($filters['maxGPA'])) {
            $query->where('gpa', '<=', (float)$filters['maxGPA']);
        }

        // 3. Status Filter (Active, Inactive, Suspended, Graduated)
        if (!empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        // 4. Program/Year Filter
        if (isset($filters['program']) && $filters['program'] !== 'All') {
            $query->where('program', $filters['program']);
        }
        if (isset($filters['yearLevel']) && $filters['yearLevel'] !== 'All') {
            $query->where('year_level', (int)$filters['yearLevel']);
        }

        // 4. Violation Filter
        if (isset($filters['hasViolations'])) {
            if ($filters['hasViolations'] === 'yes') {
                $query->has('violations');
            } elseif ($filters['hasViolations'] === 'no') {
                $query->doesntHave('violations');
            }
        }

        // 5. Sports Filter
        if (!empty($filters['sports'])) {
            $sports = is_array($filters['sports']) ? $filters['sports'] : [$filters['sports']];
            $query->whereHas('sportsSkills', function($q) use ($sports) {
                $q->whereIn('sport', $sports);
            });
        }

        // 6. Height Filter
        if (!empty($filters['minHeight'])) {
            $query->whereHas('sportsSkills', function($q) use ($filters) {
                $q->where('height', '>=', (float)$filters['minHeight']);
            });
        }

        // 7. General Search (Name, Student Number, Email)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                  ->orWhere('last_name', 'LIKE', "%{$search}%")
                  ->orWhere('student_number', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if (isset($filters['perPage']) && (int)$filters['perPage'] === -1) {
            return $query->latest()->get();
        }

        return $query->latest()->paginate($filters['perPage'] ?? 15);
    }

    /**
     * Get a specific student with all their detailed relationships.
     */
    public function getStudentWithRelations($id): Student
    {
        return Student::with([
            'academicHistories',
            'sportsSkills',
            'affiliations',
            'violations',
            'activities'
        ])->findOrFail($id);
    }

    /**
     * Update a specific student's basic details.
     */
    public function updateStudent($id, array $data): Student
    {
        $student = Student::findOrFail($id);
        
        $mappedData = [
            'first_name' => $data['firstName'] ?? null,
            'middle_name' => $data['middleName'] ?? null,
            'last_name' => $data['lastName'] ?? null,
            'student_number' => $data['studentNumber'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['dateOfBirth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'address' => $data['address'] ?? null,
            'enrollment_date' => $data['enrollmentDate'] ?? null,
            'program' => $data['program'] ?? null,
            'section' => $data['section'] ?? null,
            'year_level' => $data['yearLevel'] ?? null,
            'status' => $data['status'] ?? null,
            'gpa' => $data['gpa'] ?? null,
            'classification' => $data['classification'] ?? null,
            'technical_skills' => $data['technicalSkills'] ?? null,
            'other_skills' => $data['otherSkills'] ?? null,
        ];

        $mappedData = array_filter($mappedData, function($value) {
            return $value !== null;
        });

        // Use DB transaction to rollback in case of future nested inserts failing
        \Illuminate\Support\Facades\DB::transaction(function () use ($student, $mappedData, $data) {
            $student->update($mappedData);
            
            // Sync Sports Skills
            if (isset($data['sportsSkills']) && is_array($data['sportsSkills'])) {
                $student->sportsSkills()->delete(); // Clear existing sports relations
                foreach ($data['sportsSkills'] as $sport) {
                    if (!empty($sport['sport'])) {
                        $student->sportsSkills()->create([
                            'sport'    => $sport['sport'],
                            'level'    => $sport['level'] ?? 'Beginner',
                            'height'   => $sport['height'] ?? null,
                            'weight'   => $sport['weight'] ?? null,
                            'position' => $sport['position'] ?? null,
                        ]);
                    }
                }
            }

            // Sync Affiliations
            if (isset($data['affiliations']) && is_array($data['affiliations'])) {
                $student->affiliations()->delete(); // Clear existing affiliations
                foreach ($data['affiliations'] as $aff) {
                    if (!empty($aff['organization'])) {
                        $student->affiliations()->create([
                            'organization' => $aff['organization'],
                            'role'         => $aff['role'] ?? null,
                            'join_date'    => $aff['joinDate'] ?? null,
                            'status'       => $aff['status'] ?? 'Active',
                        ]);
                    }
                }
            }

            // Sync Violations
            $hasActiveSuspension = false;
            if (isset($data['violations']) && is_array($data['violations'])) {
                $student->violations()->delete(); // Clear existing violations
                foreach ($data['violations'] as $violation) {
                    if (!empty($violation['type'])) {
                        $student->violations()->create([
                            'type'            => $violation['type'],
                            'date'            => $violation['date'] ?? null,
                            'description'     => $violation['description'] ?? null,
                            'severity'        => $violation['severity'] ?? 'Minor',
                            'resolution'      => $violation['resolution'] ?? null,
                            'suspended_until' => $violation['suspendedUntil'] ?? null,
                        ]);
                        
                        // Check if this violation warrants an active suspension
                        if (!empty($violation['suspendedUntil']) && strtotime($violation['suspendedUntil']) >= strtotime(date('Y-m-d'))) {
                            $hasActiveSuspension = true;
                        }
                    }
                }
            }

            // Immediately enforce suspension status
            if ($hasActiveSuspension) {
                $student->update(['status' => 'Suspended']);
            } elseif ($student->status === 'Suspended' && isset($data['violations'])) {
                $student->update(['status' => 'Active']);
            }

            // Sync Non Academic Activities
            if (isset($data['nonAcademicActivities']) && is_array($data['nonAcademicActivities'])) {
                $student->activities()->delete();
                foreach ($data['nonAcademicActivities'] as $act) {
                    if (!empty($act['name'])) {
                        $student->activities()->create([
                            'name'        => $act['name'],
                            'type'        => $act['type'] ?? 'Extra-Curricular',
                            'date'        => $act['date'] ?? null,
                            'description' => $act['description'] ?? null,
                            'award'       => $act['award'] ?? null,
                        ]);
                    }
                }
            }

            // Sync Academic Histories
            if (isset($data['academicHistory']) && is_array($data['academicHistory'])) {
                $student->academicHistories()->delete();
                foreach ($data['academicHistory'] as $record) {
                    if (!empty($record['semester'])) {
                        $student->academicHistories()->create([
                            'semester' => $record['semester'],
                            'semester_gpa' => $record['semesterGPA'] ?? 0.0,
                            'courses' => json_encode($record['courses'] ?? []),
                        ]);
                    }
                }
            }
        });
        
        return $this->getStudentWithRelations($id);
    }

    /**
     * Create a new student.
     */
    public function createStudent(array $data): Student
    {
        $mappedData = [
            'first_name' => $data['firstName'] ?? null,
            'middle_name' => $data['middleName'] ?? null,
            'last_name' => $data['lastName'] ?? null,
            'student_number' => $data['studentNumber'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['dateOfBirth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'address' => $data['address'] ?? null,
            'enrollment_date' => $data['enrollmentDate'] ?? null,
            'program' => $data['program'] ?? null,
            'section' => $data['section'] ?? null,
            'year_level' => $data['yearLevel'] ?? 1,
            'status' => $data['status'] ?? 'Active',
            'gpa' => $data['gpa'] ?? 0.0,
            'classification' => $data['classification'] ?? 'Regular',
            'technical_skills' => $data['technicalSkills'] ?? [],
            'other_skills' => $data['otherSkills'] ?? [],
        ];

        $mappedData = array_filter($mappedData, function($value) {
            return $value !== null;
        });

        // Wrap the insert in a database transaction to ensure rollback if any part fails
        $student = \Illuminate\Support\Facades\DB::transaction(function () use ($mappedData, $data) {
            $newStudent = Student::create($mappedData);
            
            // Sync Sports Skills
            if (isset($data['sportsSkills']) && is_array($data['sportsSkills'])) {
                foreach ($data['sportsSkills'] as $sport) {
                    if (!empty($sport['sport'])) {
                        $newStudent->sportsSkills()->create([
                            'sport'    => $sport['sport'],
                            'level'    => $sport['level'] ?? 'Beginner',
                            'height'   => $sport['height'] ?? null,
                            'weight'   => $sport['weight'] ?? null,
                            'position' => $sport['position'] ?? null,
                        ]);
                    }
                }
            }

            // Sync Affiliations
            if (isset($data['affiliations']) && is_array($data['affiliations'])) {
                foreach ($data['affiliations'] as $aff) {
                    if (!empty($aff['organization'])) {
                        $newStudent->affiliations()->create([
                            'organization' => $aff['organization'],
                            'role'         => $aff['role'] ?? null,
                            'join_date'    => $aff['joinDate'] ?? null,
                            'status'       => $aff['status'] ?? 'Active',
                        ]);
                    }
                }
            }

            // Sync Violations
            $hasActiveSuspension = false;
            if (isset($data['violations']) && is_array($data['violations'])) {
                foreach ($data['violations'] as $violation) {
                    if (!empty($violation['type'])) {
                        $newStudent->violations()->create([
                            'type'            => $violation['type'],
                            'date'            => $violation['date'] ?? null,
                            'description'     => $violation['description'] ?? null,
                            'severity'        => $violation['severity'] ?? 'Minor',
                            'resolution'      => $violation['resolution'] ?? null,
                            'suspended_until' => $violation['suspendedUntil'] ?? null,
                        ]);
                        
                        if (!empty($violation['suspendedUntil']) && strtotime($violation['suspendedUntil']) >= strtotime(date('Y-m-d'))) {
                            $hasActiveSuspension = true;
                        }
                    }
                }
            }
            
            if ($hasActiveSuspension) {
                $newStudent->update(['status' => 'Suspended']);
            }

            // Sync Non Academic Activities
            if (isset($data['nonAcademicActivities']) && is_array($data['nonAcademicActivities'])) {
                foreach ($data['nonAcademicActivities'] as $act) {
                    if (!empty($act['name'])) {
                        $newStudent->activities()->create([
                            'name'        => $act['name'],
                            'type'        => $act['type'] ?? 'Extra-Curricular',
                            'date'        => $act['date'] ?? null,
                            'description' => $act['description'] ?? null,
                            'award'       => $act['award'] ?? null,
                        ]);
                    }
                }
            }

            // Sync Academic Histories
            if (isset($data['academicHistory']) && is_array($data['academicHistory'])) {
                foreach ($data['academicHistory'] as $record) {
                    if (!empty($record['semester'])) {
                        $newStudent->academicHistories()->create([
                            'semester' => $record['semester'],
                            'semester_gpa' => $record['semesterGPA'] ?? 0.0,
                            'courses' => json_encode($record['courses'] ?? []),
                        ]);
                    }
                }
            }
            
            return $newStudent;
        });
        
        return $this->getStudentWithRelations($student->id);
    }

    /**
     * Delete a student and all associated relational records securely.
     */
    public function deleteStudent(string $id): void
    {
        $student = Student::findOrFail($id);
        
        \Illuminate\Support\Facades\DB::transaction(function () use ($student) {
            // Delete associated relational dependencies explicitly, 
            // incase foreign key cascades aren't fully configured
            $student->academicHistories()->delete();
            $student->sportsSkills()->delete();
            $student->affiliations()->delete();
            $student->violations()->delete();
            $student->activities()->delete();
            
            // Finally delete the student record
            $student->delete();
        });
    }
}
