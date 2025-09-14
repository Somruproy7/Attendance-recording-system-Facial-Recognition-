<?php
// ss/face_recognition_api.php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$image_data = $input['image'] ?? '';
$expected_student_id = $input['student_id'] ?? '';

if (empty($image_data) || empty($expected_student_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing image or student ID']);
    exit();
}

try {
    // Save image temporarily
    $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
    $image_data = str_replace(' ', '+', $image_data);
    $image_binary = base64_decode($image_data);
    
    $temp_file = tempnam(sys_get_temp_dir(), 'face_check_');
    file_put_contents($temp_file, $image_binary);
    
    // Use the enhanced face recognition from SS folder
    $python_script = __DIR__ . '/face_recognition_check.py';
    
    // Create a temporary Python script for this recognition
    $script_content = '
import cv2
import os
import numpy as np
import sys
import json

def load_reference_face(student_id):
    """Load reference photo for specific student"""
    photos_dir = "../uploads/profiles"
    image_extensions = [".jpg", ".jpeg", ".png", ".bmp"]
    
    if not os.path.exists(photos_dir):
        return None, None
    
    target_filename = str(student_id)
    
    for filename in os.listdir(photos_dir):
        name_without_ext = os.path.splitext(filename)[0]
        if name_without_ext == target_filename and any(filename.lower().endswith(ext) for ext in image_extensions):
            image_path = os.path.join(photos_dir, filename)
            
            try:
                # Load image
                img = cv2.imread(image_path)
                if img is None:
                    continue
                    
                gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
                
                # Load face cascade
                face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
                
                # Detect faces
                faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(30, 30))
                
                if len(faces) > 0:
                    # Take the largest face
                    largest_face = max(faces, key=lambda x: x[2] * x[3])
                    x, y, w, h = largest_face
                    
                    # Extract face region
                    face_roi = gray[y:y+h, x:x+w]
                    face_roi = cv2.resize(face_roi, (100, 100))
                    
                    return face_roi, face_cascade
                    
            except Exception as e:
                continue
    
    return None, None

def compare_faces(face1, face2, threshold=0.4):
    """Compare two face images using template matching"""
    try:
        if face1.shape != face2.shape:
            face2 = cv2.resize(face2, (face1.shape[1], face1.shape[0]))
        
        # Normalize images
        face1_norm = cv2.equalizeHist(face1)
        face2_norm = cv2.equalizeHist(face2)
        
        # Template matching
        result = cv2.matchTemplate(face1_norm, face2_norm, cv2.TM_CCOEFF_NORMED)
        similarity = np.max(result)
        
        return similarity > threshold, similarity
    except Exception as e:
        return False, 0.0

def check_face_in_image(image_path, student_id):
    """Check if student face is in the provided image"""
    try:
        # Load reference face
        ref_face, face_cascade = load_reference_face(student_id)
        if ref_face is None or face_cascade is None:
            return {"success": False, "message": "Reference photo not found"}
        
        # Load test image
        img = cv2.imread(image_path)
        if img is None:
            return {"success": False, "message": "Invalid image"}
        
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        
        # Detect faces in test image
        faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(30, 30))
        
        if len(faces) == 0:
            return {"success": True, "matched": False, "message": "No face detected"}
        
        best_similarity = 0.0
        matched = False
        
        for (x, y, w, h) in faces:
            # Extract face region
            face_roi = gray[y:y+h, x:x+w]
            face_roi = cv2.resize(face_roi, (100, 100))
            
            # Compare with reference
            is_match, similarity = compare_faces(ref_face, face_roi)
            if is_match and similarity > best_similarity:
                best_similarity = similarity
                matched = True
        
        return {
            "success": True,
            "matched": matched,
            "confidence": float(best_similarity),
            "student_id": student_id
        }
        
    except Exception as e:
        return {"success": False, "message": f"Processing error: {str(e)}"}

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print(json.dumps({"success": False, "message": "Invalid arguments"}))
        sys.exit(1)
    
    image_path = sys.argv[1]
    student_id = int(sys.argv[2])
    
    result = check_face_in_image(image_path, student_id)
    print(json.dumps(result))
';
    
    file_put_contents($python_script, $script_content);
    
    // Run Python script with full path
    $python_path = 'python'; // Try python first
    $command = "\"$python_path\" \"$python_script\" \"$temp_file\" \"$expected_student_id\" 2>&1";
    $output = shell_exec($command);
    
    // If python doesn't work, try python3
    if (empty($output) || strpos($output, 'is not recognized') !== false) {
        $python_path = 'python3';
        $command = "\"$python_path\" \"$python_script\" \"$temp_file\" \"$expected_student_id\" 2>&1";
        $output = shell_exec($command);
    }
    
    // If still not working, try with full Windows path
    if (empty($output) || strpos($output, 'is not recognized') !== false) {
        $python_path = 'C:\\Python311\\python.exe'; // Common Windows Python path
        if (file_exists($python_path)) {
            $command = "\"$python_path\" \"$python_script\" \"$temp_file\" \"$expected_student_id\" 2>&1";
            $output = shell_exec($command);
        }
    }
    
    // Clean up temporary files
    unlink($temp_file);
    if (file_exists($python_script)) {
        unlink($python_script);
    }
    
    // Parse result
    $result = json_decode($output, true);
    
    if ($result === null) {
        // Log the raw output for debugging
        error_log("Face recognition output: " . $output);
        echo json_encode([
            'success' => false, 
            'message' => 'Face recognition processing failed',
            'debug_output' => $output,
            'command' => $command
        ]);
    } else {
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
