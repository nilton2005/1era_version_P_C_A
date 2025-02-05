<?php
/**
 * Plugin Name: Sistema de Certificados
 * Description: Sistema para generar y gestionar certificados
 * Version: 1.0
 * Author: Oracle Perú S.A.C
 */

if (!defined('ABSPATH')) {
    exit;
}

class CertificateSystem {
    private static $instance = null;
    private $config;
    
    private function __construct() {
        $this->init_config();
        $this->init_hooks();
    }
    
    // Implementación Singleton
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_config() {
        $this->config = [
            'root_folder_id' => '1hl1XZm3lrUGkPfXkoYowH0mj4-Of9Zuc',
            'templates_path' => __DIR__ . '/templates',
            'fonts_path' => __DIR__ . '/assets/fonts',
            'credentials_path' => __DIR__ . '/credentials.json',
            'minimum_grade' => 15,
            'pdf_settings' => [
                'orientation' => 'L',
                'unit' => 'mm',
                'format' => 'A4'
            ],
            'digital_signature' => [
                'certificate' => __DIR__ . '/assets/digital-signature/public.crt',
                'key' => __DIR__ . '/assets/digital-signature/private.key',
                'password' => 'gutemberg192837465',
                'info' => [
                    'Name' => 'Oracle Perú S.A.C',
                    'Location' => 'Arequipa',
                    'Reason' => 'Certificado de aprobación',
                    'ContactInfo' => 'https://www.consultoriaoracleperusac.org.pe/'
                ]
            ]
        ];
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);
        add_action('init', [$this, 'init_directories']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        if (!wp_next_scheduled('generate_pending_certificates')) {
            wp_schedule_event(time(), 'hourly', 'generate_pending_certificates');
        }
        add_action('generate_pending_certificates', [$this, 'process_pending_certificates']);
    }

    public function activate_plugin() {
        $this->create_tables();
        $this->init_directories();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}certificados_generados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dni VARCHAR(20) NULL,
            codigo_unico VARCHAR(100) NOT NULL,
            student_id BIGINT(20) NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            curso VARCHAR(100) NOT NULL,
            course_id BIGINT(20) NOT NULL,
            nota FLOAT,
            fecha_emision DATE,
            emisor VARCHAR(100),
            enlace_drive TEXT,
            status ENUM('pending', 'processing', 'completed', 'error') DEFAULT 'pending',
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY codigo_unico (codigo_unico),
            INDEX idx_student_course (student_id, course_id),
            INDEX idx_status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function process_pending_certificates() {
        try {
            $certificate_generator = new CertificateGenerator($this->config);
            $drive_manager = new DriveManager($this->config);
            
            $pending_students = $this->get_pending_students();
            
            foreach ($pending_students as $student) {
                try {
                    $this->update_certificate_status($student->ID, 'processing');
                    
                    // Generar certificado
                    $certificate_data = $certificate_generator->generate($student);
                    
                    // Subir a Drive
                    $drive_url = $drive_manager->upload_certificate($certificate_data);
                    
                    // Actualizar base de datos
                    $this->save_certificate([
                        'student_id' => $student->ID,
                        'course_id' => $student->course_id,
                        'enlace_drive' => $drive_url,
                        'status' => 'completed'
                    ]);
                    
                } catch (Exception $e) {
                    $this->update_certificate_status(
                        $student->ID, 
                        'error',
                        $e->getMessage()
                    );
                    $this->log_error($e);
                }
            }
        } catch (Exception $e) {
            $this->log_error($e);
        }
    }

    private function get_pending_students() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT u.ID, 
                   u.display_name AS nombre_completo,
                   um.meta_value AS dni,
                   p.post_title AS course_name,
                   q.attempt_started_at AS ultima_fecha, 
                   q.earned_marks AS nota,
                   q.course_id
            FROM {$wpdb->prefix}tutor_quiz_attempts q
            JOIN {$wpdb->users} u ON q.user_id = u.ID
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'dni'
            JOIN {$wpdb->prefix}posts p ON q.course_id = p.ID
            WHERE q.earned_marks >= {$this->config['minimum_grade']}
            AND NOT EXISTS (
                SELECT 1 
                FROM {$wpdb->prefix}certificados_generados c
                WHERE c.student_id = u.ID 
                AND c.course_id = q.course_id
                AND c.status != 'error'
            )
        ");
    }

    private function update_certificate_status($student_id, $status, $error_message = null) {
        global $wpdb;
        $data = ['status' => $status];
        if ($error_message) {
            $data['error_message'] = $error_message;
        }
        $wpdb->update(
            $wpdb->prefix . 'certificados_generados',
            $data,
            ['student_id' => $student_id]
        );
    }

    private function log_error(Exception $e) {
        error_log(sprintf(
            'Error en generación de certificados: %s en %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}

// Inicializar el sistema
add_action('plugins_loaded', function() {
    CertificateSystem::get_instance();
});

class CertificateGenerator {
    private $config;
    private $temp_dir;

    public function __construct($config) {
        $this->config = $config;
        $this->temp_dir = $this->config['templates_path'] . '/temp';
        if (!file_exists($this->temp_dir)) {
            mkdir($this->temp_dir, 0755, true);
        }
    }

    public function generate($student_data) {
        try {
            $certificate_id = wp_generate_uuid4();
            
            $image_paths = $this->generate_certificate_images($student_data, $certificate_id);
            
            // convertir a PDF
            $pdf_path = $this->convert_to_pdf($image_paths, $student_data);
            
           
            $signed_pdf_path = $this->sign_pdf($pdf_path);
            
            $this->cleanup_temp_files($image_paths);
            
            return [
                'certificate_id' => $certificate_id,
                'pdf_path' => $signed_pdf_path,
                'student_data' => $student_data
            ];
            
        } catch (Exception $e) {
            $this->log_error($e);
            throw new Exception('Error generando certificado: ' . $e->getMessage());
        }
    }

    private function generate_certificate_images($student_data, $certificate_id) {
        $image1 = $this->create_first_page($student_data, $certificate_id);
        $image2 = $this->create_second_page($student_data);
        
        $paths = [
            'page1' => $this->temp_dir . "/cert1_{$certificate_id}.png",
            'page2' => $this->temp_dir . "/cert2_{$certificate_id}.png"
        ];
        
        imagepng($image1, $paths['page1']);
        imagepng($image2, $paths['page2']);
        
        imagedestroy($image1);
        imagedestroy($image2);
        
        return $paths;
    }

    private function create_first_page($student_data, $certificate_id) {
        $image = imagecreatefrompng($this->config['templates_path'] . '/Diapositiva1.png');
        
        // Configuración de colores
        $colors = [
            'name' => imagecolorallocate($image, 0, 32, 96),
            'dni' => imagecolorallocate($image, 53, 55, 68),
            'course' => imagecolorallocate($image, 7, 55, 99),
            'date' => imagecolorallocate($image, 53, 55, 68)
        ];
        
        // Configuración de fuentes
        $fonts = $this->get_fonts();
        
        // Agregar textos
        $this->add_text_to_image($image, [
            [
                'text' => $student_data->nombre_completo,
                'size' => 30,
                'x' => 300,
                'y' => 300,
                'color' => $colors['name'],
                'font' => $fonts['name']
            ],
            [
                'text' => $student_data->dni,
                'size' => 14,
                'x' => 250,
                'y' => 400,
                'color' => $colors['dni'],
                'font' => $fonts['dni']
            ],
            [
                'text' => $student_data->course_name,
                'size' => 25,
                'x' => 250,
                'y' => 500,
                'color' => $colors['course'],
                'font' => $fonts['course']
            ]
        ]);
        
        // Agregar QR
        $this->add_qr_code($image, $certificate_id, 200, 400);
        
        return $image;
    }

    private function create_second_page($student_data) {
        $image = imagecreatefrompng($this->config['templates_path'] . '/Diapositiva2.png');
        
        
        return $image;
    }

    private function convert_to_pdf($image_paths, $student_data) {
        $pdf = new TCPDF(
            $this->config['pdf_settings']['orientation'],
            $this->config['pdf_settings']['unit'],
            $this->config['pdf_settings']['format']
        );
        
        $pdf->AddPage();
        $pdf->Image($image_paths['page1'], 0, 0, 297, 210);
        $pdf->AddPage();
        $pdf->Image($image_paths['page2'], 0, 0, 297, 210);
        
        $pdf_path = $this->temp_dir . "/certificate_{$student_data->ID}.pdf";
        $pdf->Output($pdf_path, 'F');
        
        return $pdf_path;
    }

    private function sign_pdf($pdf_path) {
        $pdf = new TCPDF();
        $certificate = $this->config['digital_signature']['certificate'];
        $key = $this->config['digital_signature']['key'];
        $password = $this->config['digital_signature']['password'];
        
        $pdf->setSignature($certificate, $key, $password, '', 2, $this->config['digital_signature']['info']);
        
        $signed_path = str_replace('.pdf', '_signed.pdf', $pdf_path);
        $pdf->Output($signed_path, 'F');
        
        return $signed_path;
    }

    private function get_fonts() {
        return [
            'name' => $this->config['fonts_path'] . '/Nunito-Italic-VariableFont_wght.ttf',
            'dni' => $this->config['fonts_path'] . '/Arimo-Italic-VariableFont_wght.ttf',
            'course' => $this->config['fonts_path'] . '/DMSerifText-Regular.ttf'
        ];
    }

    private function cleanup_temp_files($paths) {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function log_error($e) {
        error_log(sprintf(
            'Error en generación de imágenes del certificado: %s en %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}


class DriveManager {
    private $google_client;
    private $drive_service;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->init_google_client();
    }

    private function init_google_client() {
        $this->google_client = new Google_Client();
        $this->google_client->setAuthConfig($this->config['credentials_path']);
        $this->google_client->addScope(Google_Service_Drive::DRIVE);
        $this->drive_service = new Google_Service_Drive($this->google_client);
    }

    public function upload_certificate($certificate_data) {
        try {
            // Crear estructura de carpetas
            $folder_path = $this->build_folder_path($certificate_data['student_data']);
            $folder_id = $this->ensure_folder_structure($folder_path);
            
            // Subir archivo
            $file_id = $this->upload_file(
                $certificate_data['pdf_path'],
                $folder_id,
                $certificate_data['student_data']
            );
            
            // Configurar permisos
            $this->set_file_permissions($file_id);
            
            // Generar URL de descarga
            $download_url = $this->generate_download_url($file_id);
            
            return $download_url;
            
        } catch (Exception $e) {
            $this->log_error($e);
            throw new Exception('Error subiendo a Drive: ' . $e->getMessage());
        }
    }

    private function build_folder_path($student_data) {
        return implode('/', [
            date('Y'),
            sanitize_title($student_data->course_name),
            sanitize_title($student_data->nombre_completo)
        ]);
    }

    private function ensure_folder_structure($folder_path) {
        $folders = explode('/', $folder_path);
        $parent_id = $this->config['root_folder_id'];
        
        foreach ($folders as $folder_name) {
            $parent_id = $this->get_or_create_folder($folder_name, $parent_id);
        }
        
        return $parent_id;
    }

    private function get_or_create_folder($folder_name, $parent_id) {
        // Buscar carpeta existente
        $folder_id = $this->find_folder($folder_name, $parent_id);
        
        // Si no existe, crearla
        if (!$folder_id) {
            $folder_id = $this->create_folder($folder_name, $parent_id);
        }
        
        return $folder_id;
    }

    private function find_folder($folder_name, $parent_id) {
        $response = $this->drive_service->files->listFiles([
            'q' => "name='$folder_name' and mimeType='application/vnd.google-apps.folder' and '$parent_id' in parents and trashed=false",
            'spaces' => 'drive',
            'fields' => 'files(id, name)'
        ]);
        
        return count($response->files) > 0 ? $response->files[0]->id : null;
    }

    private function create_folder($folder_name, $parent_id) {
        $folder_metadata = new Google_Service_Drive_DriveFile([
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parent_id]
        ]);
        
        $folder = $this->drive_service->files->create($folder_metadata, [
            'fields' => 'id'
        ]);
        
        return $folder->id;
    }

    private function upload_file($file_path, $folder_id, $student_data) {
        $file_metadata = new Google_Service_Drive_DriveFile([
            'name' => $this->generate_filename($student_data),
            'parents' => [$folder_id]
        ]);
        
        $content = file_get_contents($file_path);
        $file = $this->drive_service->files->create($file_metadata, [
            'data' => $content,
            'mimeType' => 'application/pdf',
            'uploadType' => 'multipart',
            'fields' => 'id'
        ]);
        
        return $file->id;
    }

    private function set_file_permissions($file_id) {
        $permission = new Google_Service_Drive_Permission([
            'type' => 'anyone',
            'role' => 'reader'
        ]);
        
        $this->drive_service->permissions->create(
            $file_id,
            $permission,
            ['fields' => 'id']
        );
    }

    private function generate_download_url($file_id) {
        return "https://drive.google.com/uc?export=download&id=" . $file_id;
    }

    private function generate_filename($student_data) {
        return sprintf(
            'certificado_%s_%s.pdf',
            sanitize_title($student_data->nombre_completo),
            date('Y-m-d')
        );
    }

    private function log_error($e) {
        error_log(sprintf(
            'Error en Drive Manager: %s en %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}