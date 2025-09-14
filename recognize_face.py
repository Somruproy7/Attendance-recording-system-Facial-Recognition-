import argparse
import base64
import json
import os
import sys
import tempfile
import numpy as np
import mysql.connector
import cv2
import time
import threading


# Directory containing reference photos
PHOTOS_DIR = os.path.join(os.path.dirname(__file__), 'uploads', 'profiles')

DB_CONFIG = {
	'host': 'localhost',
	'database': 'fullattend_db',
	'user': 'root',
	'password': ''
}


class RobustFaceRecognition:
    def __init__(self):
        self.reference_faces = []
        self.reference_names = []
        self.reference_student_ids = []
        self.face_cascade = None
        self.cap = None
        self.current_camera_index = 0
        self.available_cameras = []
        self.camera_lock = threading.Lock()
        self.camera_names = {}
        self.running = True
        self.last_matches = []
        
    def initialize(self):
        """Initialize the face recognition system"""
        try:
            # Load face cascade classifier
            self.face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
            
            # Load reference faces
            self.load_reference_faces()
            
            # Find available cameras
            self.find_available_cameras()
            
            # Initialize camera
            self.initialize_camera()
            
            return True
        except Exception as e:
            print(f"Initialization error: {e}")
            return False
    
    def load_reference_faces(self):
        """Load reference photos and detect faces in them"""
        image_extensions = ['.jpg', '.jpeg', '.png', '.bmp']
        
        print("Loading reference photos...")
        if not os.path.exists(PHOTOS_DIR):
            print("No profiles directory found")
            return
            
        for filename in os.listdir(PHOTOS_DIR):
            if any(filename.lower().endswith(ext) for ext in image_extensions):
                image_path = os.path.join(PHOTOS_DIR, filename)
                
                try:
                    # Load image
                    img = cv2.imread(image_path)
                    if img is None:
                        continue
                        
                    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
                    
                    # Detect faces
                    faces = self.face_cascade.detectMultiScale(
                        gray, 
                        scaleFactor=1.1, 
                        minNeighbors=5, 
                        minSize=(30, 30)
                    )
                    
                    if len(faces) > 0:
                        # Take the largest face
                        largest_face = max(faces, key=lambda x: x[2] * x[3])
                        x, y, w, h = largest_face
                        
                        # Extract face region
                        face_roi = gray[y:y+h, x:x+w]
                        face_roi = cv2.resize(face_roi, (100, 100))
                        
                        self.reference_faces.append(face_roi)
                        name = os.path.splitext(filename)[0]
                        self.reference_names.append(name)
                        
                        # Extract student ID from filename
                        try:
                            student_id = int(name)
                            self.reference_student_ids.append(student_id)
                            print(f"✓ Loaded reference photo: {filename} (Student ID: {student_id})")
                        except ValueError:
                            self.reference_student_ids.append(None)
                            print(f"✓ Loaded reference photo: {filename} (No student ID)")
                    else:
                        print(f"✗ No face found in: {filename}")
                        
                except Exception as e:
                    print(f"Error loading {filename}: {e}")
    
    def find_available_cameras(self):
        """Find available camera indices including external USB/webcams"""
        self.available_cameras = []
        self.camera_names = {}
        print("Scanning for available cameras (including external USB/webcams)...")
        
        # Extended range to detect more external cameras
        for i in range(20):
            try:
                # Try different backends for better external camera support
                backends = [cv2.CAP_ANY, cv2.CAP_DSHOW, cv2.CAP_V4L2, cv2.CAP_GSTREAMER]
                
                for backend in backends:
                    try:
                        cap_test = cv2.VideoCapture(i, backend)
                        if cap_test.isOpened():
                            # Set properties for external cameras
                            cap_test.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                            cap_test.set(cv2.CAP_PROP_FPS, 30)
                            
                            # Try to read multiple frames to ensure stability
                            frames_read = 0
                            for _ in range(3):
                                ret, frame = cap_test.read()
                                if ret and frame is not None and frame.size > 0:
                                    frames_read += 1
                                time.sleep(0.1)
                            
                            if frames_read >= 2:  # At least 2 successful reads
                                if i not in self.available_cameras:
                                    self.available_cameras.append(i)
                                    
                                    # Try to get camera info
                                    width = int(cap_test.get(cv2.CAP_PROP_FRAME_WIDTH))
                                    height = int(cap_test.get(cv2.CAP_PROP_FRAME_HEIGHT))
                                    fps = int(cap_test.get(cv2.CAP_PROP_FPS))
                                    
                                    camera_info = f"Camera {i} ({width}x{height}@{fps}fps)"
                                    if backend == cv2.CAP_DSHOW:
                                        camera_info += " [DirectShow]"
                                    elif backend == cv2.CAP_V4L2:
                                        camera_info += " [V4L2]"
                                    
                                    self.camera_names[i] = camera_info
                                    print(f"✓ {camera_info}")
                                    break
                            
                            cap_test.release()
                            time.sleep(0.2)  # Longer delay for external cameras
                        else:
                            cap_test.release()
                            
                    except Exception as e:
                        if cap_test:
                            cap_test.release()
                        continue
                        
            except Exception as e:
                continue
        
        # Sort cameras (built-in cameras first, then external)
        self.available_cameras.sort()
        
        if not self.available_cameras:
            print("Warning: No cameras detected!")
            print("Tips for external cameras:")
            print("- Make sure USB webcam is properly connected")
            print("- Try different USB ports")
            print("- Check if camera is being used by another application")
            print("- For Iris webcam, ensure drivers are installed")
        else:
            print(f"\nTotal cameras found: {len(self.available_cameras)}")
            for cam_id in self.available_cameras:
                print(f"  {self.camera_names.get(cam_id, f'Camera {cam_id}')}")
    
    def initialize_camera(self, backend=None):
        """Initialize camera with error handling and external camera support"""
        if not self.available_cameras:
            print("No cameras available!")
            return False
            
        camera_id = self.available_cameras[self.current_camera_index]
        
        try:
            # Release existing camera if any
            if self.cap is not None:
                self.cap.release()
                time.sleep(0.5)  # Wait for proper release
            
            # Try different backends for external cameras
            backends_to_try = [cv2.CAP_DSHOW, cv2.CAP_ANY, cv2.CAP_V4L2] if backend is None else [backend]
            
            for backend_type in backends_to_try:
                try:
                    # Initialize new camera with specific backend
                    self.cap = cv2.VideoCapture(camera_id, backend_type)
                    
                    if self.cap.isOpened():
                        # Set camera properties for better stability with external cameras
                        self.cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                        self.cap.set(cv2.CAP_PROP_FPS, 30)
                        self.cap.set(cv2.CAP_PROP_FRAME_WIDTH, 640)
                        self.cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)
                        
                        # For external USB cameras, set additional properties
                        self.cap.set(cv2.CAP_PROP_AUTOFOCUS, 1)
                        self.cap.set(cv2.CAP_PROP_AUTO_EXPOSURE, 0.25)
                        
                        # Test if camera actually works with multiple frame reads
                        successful_reads = 0
                        for attempt in range(5):
                            ret, frame = self.cap.read()
                            if ret and frame is not None and frame.size > 0:
                                successful_reads += 1
                            time.sleep(0.1)
                        
                        if successful_reads >= 3:
                            backend_name = {
                                cv2.CAP_DSHOW: "DirectShow",
                                cv2.CAP_V4L2: "V4L2", 
                                cv2.CAP_ANY: "Auto"
                            }.get(backend_type, f"Backend {backend_type}")
                            
                            camera_info = self.camera_names.get(camera_id, f"Camera {camera_id}")
                            print(f"✓ {camera_info} initialized with {backend_name}")
                            return True
                        else:
                            print(f"✗ Camera {camera_id} with backend {backend_type} failed stability test")
                            self.cap.release()
                            
                except Exception as e:
                    if self.cap:
                        self.cap.release()
                    print(f"Failed to initialize camera {camera_id} with backend {backend_type}: {e}")
                    continue
            
            print(f"✗ Failed to initialize camera {camera_id} with any backend")
            return False
                
        except Exception as e:
            print(f"Error initializing camera {camera_id}: {e}")
            return False
    
    def switch_camera(self, specific_camera=None):
        """Safely switch to next camera or specific camera"""
        with self.camera_lock:
            try:
                if specific_camera is not None:
                    if specific_camera in self.available_cameras:
                        self.current_camera_index = self.available_cameras.index(specific_camera)
                    else:
                        print(f"Camera {specific_camera} not available")
                        return False
                else:
                    # Switch to next available camera
                    self.current_camera_index = (self.current_camera_index + 1) % len(self.available_cameras)
                
                # Initialize the new camera
                success = self.initialize_camera()
                if success:
                    camera_id = self.available_cameras[self.current_camera_index]
                    print(f"Switched to camera {camera_id}")
                    return True
                else:
                    print("Failed to switch camera")
                    return False
                    
            except Exception as e:
                print(f"Error switching camera: {e}")
                return False
    
    def compare_faces(self, face1, face2, threshold=0.4):
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
            print(f"Error comparing faces: {e}")
            return False, 0.0
    
    def check_face_match(self, frame):
        """Check if any face in the frame matches known faces"""
        if frame is None:
            return []
            
        try:
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            faces = self.face_cascade.detectMultiScale(
                gray, 
                scaleFactor=1.1, 
                minNeighbors=5, 
                minSize=(30, 30)
            )
            
            matches_found = []
            
            for (x, y, w, h) in faces:
                # Extract face region
                face_roi = gray[y:y+h, x:x+w]
                face_roi = cv2.resize(face_roi, (100, 100))
                
                best_match = None
                best_similarity = 0
                
                # Compare with all reference faces
                for i, ref_face in enumerate(self.reference_faces):
                    is_match, similarity = self.compare_faces(ref_face, face_roi)
                    if is_match and similarity > best_similarity:
                        best_similarity = similarity
                        best_match = self.reference_names[i]
                
                # Draw rectangle and label
                if best_match:
                    color = (0, 255, 0)  # Green for match
                    label = f"{best_match} ({best_similarity:.2f})"
                    matches_found.append(best_match)
                else:
                    color = (0, 0, 255)  # Red for unknown
                    label = "Unknown"
                
                cv2.rectangle(frame, (x, y), (x + w, y + h), color, 2)
                cv2.rectangle(frame, (x, y - 30), (x + w, y), color, cv2.FILLED)
                cv2.putText(frame, label, (x + 5, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
            
            return matches_found
            
        except Exception as e:
            print(f"Error in face matching: {e}")
            return []
    
    def run(self):
        """Main application loop"""
        if not self.initialize():
            print("Failed to initialize face recognition system")
            return
        
        if not self.reference_faces:
            print("No reference faces loaded. Please add photos to the directory.")
            return
        
        print(f"Loaded {len(self.reference_faces)} reference face(s)")
        print(f"Available cameras: {self.available_cameras}")
        print("\nStarting face recognition...")
        print("Controls:")
        print("- 'q': quit")
        print("- 's': capture and check for matches")
        print("- 'c': switch to next camera")
        print("- '0-9': switch to specific camera")
        print("- 'r': refresh/rescan cameras")
        print("- 'i': show camera info")
        
        frame_count = 0
        last_camera_check = time.time()
        
        while self.running:
            try:
                with self.camera_lock:
                    if self.cap is None or not self.cap.isOpened():
                        print("Camera not available, trying to reinitialize...")
                        if not self.initialize_camera():
                            time.sleep(1)
                            continue
                    
                    ret, frame = self.cap.read()
                
                if not ret or frame is None:
                    print("Failed to read frame, trying to reinitialize camera...")
                    time.sleep(0.1)
                    if time.time() - last_camera_check > 2:  # Try reinitializing every 2 seconds
                        self.initialize_camera()
                        last_camera_check = time.time()
                    continue
                
                frame_count += 1
                
                # Check for face matches (reduce frequency for performance)
                if frame_count % 5 == 0:  # Process every 5th frame
                    matches = self.check_face_match(frame)
                    self.last_matches = matches  # Store last result
                else:
                    matches = self.last_matches  # Use last known result
                
                # Display status
                status_text = "MATCH FOUND!" if matches else "No match"
                status_color = (0, 255, 0) if matches else (0, 0, 255)
                cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, status_color, 2)
                
                if matches:
                    match_text = f"Matched: {', '.join(matches)}"
                    cv2.putText(frame, match_text, (10, 70), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)
                
                # Display current camera info
                camera_id = self.available_cameras[self.current_camera_index] if self.available_cameras else "N/A"
                camera_info = self.camera_names.get(camera_id, f"Camera {camera_id}")
                cv2.putText(frame, camera_info, (10, frame.shape[0] - 30), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                cv2.putText(frame, f"Total cameras: {len(self.available_cameras)}", (10, frame.shape[0] - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                
                # Display the frame
                cv2.imshow('Face Recognition - Press Q to quit', frame)
                
                key = cv2.waitKey(1) & 0xFF
                if key == ord('q'):
                    self.running = False
                    break
                elif key == ord('s'):
                    # Capture and check
                    matches = self.check_face_match(frame)
                    if matches:
                        print(f"✓ SUCCESS: Face matched with {', '.join(matches)}")
                    else:
                        print("✗ NO MATCH: Face not recognized")
                elif key == ord('c'):
                    print("Switching camera...")
                    self.switch_camera()
                elif key >= ord('0') and key <= ord('9'):
                    requested_camera = int(chr(key))
                    print(f"Switching to camera {requested_camera}...")
                    self.switch_camera(requested_camera)
                elif key == ord('r'):
                    print("Rescanning for cameras...")
                    self.find_available_cameras()
                    if self.available_cameras:
                        self.current_camera_index = 0
                        self.initialize_camera()
                elif key == ord('i'):
                    print("\n=== Camera Information ===")
                    for i, cam_id in enumerate(self.available_cameras):
                        current_marker = " <- CURRENT" if i == self.current_camera_index else ""
                        print(f"  [{i}] {self.camera_names.get(cam_id, f'Camera {cam_id}')}{current_marker}")
                    print("==========================")
                    
            except KeyboardInterrupt:
                print("\nReceived keyboard interrupt")
                break
            except Exception as e:
                print(f"Error in main loop: {e}")
                time.sleep(0.1)
        
        self.cleanup()
    
    def cleanup(self):
        """Clean up resources"""
        try:
            if self.cap is not None:
                self.cap.release()
            cv2.destroyAllWindows()
            print("Cleanup completed")
        except Exception as e:
            print(f"Error during cleanup: {e}")


# Legacy functions for backward compatibility with existing PHP integration
def load_reference_faces():
    """Load reference photos from uploads/profiles directory and detect faces in them"""
    reference_faces = []
    reference_names = []
    reference_student_ids = []
    
    # Directory containing reference photos
    photos_dir = PHOTOS_DIR
    
    # Load face cascade classifier
    face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
    
    # Supported image extensions
    image_extensions = ['.jpg', '.jpeg', '.png', '.bmp']
    
    if not os.path.exists(photos_dir):
        return reference_faces, reference_names, reference_student_ids
    
    for filename in os.listdir(photos_dir):
        if any(filename.lower().endswith(ext) for ext in image_extensions):
            image_path = os.path.join(photos_dir, filename)
            
            # Load image
            img = cv2.imread(image_path)
            if img is None:
                continue
                
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            
            # Detect faces
            faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(30, 30))
            
            if len(faces) > 0:
                # Take the largest face
                largest_face = max(faces, key=lambda x: x[2] * x[3])
                x, y, w, h = largest_face
                
                # Extract face region
                face_roi = gray[y:y+h, x:x+w]
                face_roi = cv2.resize(face_roi, (100, 100))  # Normalize size
                
                reference_faces.append(face_roi)
                name = os.path.splitext(filename)[0]
                reference_names.append(name)
                
                # Extract student ID from filename (assuming format like "123.jpg" where 123 is student_id)
                try:
                    student_id = int(name)
                    reference_student_ids.append(student_id)
                except ValueError:
                    reference_student_ids.append(None)
    
    return reference_faces, reference_names, reference_student_ids


def compare_faces(face1, face2, threshold=0.6):
    """Compare two face images using template matching"""
    if face1.shape != face2.shape:
        face2 = cv2.resize(face2, (face1.shape[1], face1.shape[0]))
    
    # Normalize images
    face1_norm = cv2.equalizeHist(face1)
    face2_norm = cv2.equalizeHist(face2)
    
    # Template matching
    result = cv2.matchTemplate(face1_norm, face2_norm, cv2.TM_CCOEFF_NORMED)
    similarity = np.max(result)
    
    return similarity > threshold, similarity


def detect_face(gray):
    """Detect face in grayscale image"""
    cascade_path = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
    face_cascade = cv2.CascadeClassifier(cascade_path)
    faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(30, 30))
    if len(faces) == 0:
        return None
    x, y, w, h = max(faces, key=lambda f: f[2] * f[3])
    return gray[y:y + h, x:x + w]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image_path', help='Path to image file', default=None)
    parser.add_argument('--image_base64', help='Data URL or base64 image string', default=None)
    parser.add_argument('--threshold', type=float, default=0.6)
    parser.add_argument('--expected_student_id', type=int, default=None)
    parser.add_argument('--interactive', action='store_true', help='Run interactive face recognition')
    args = parser.parse_args()

    # If interactive mode requested, run the robust face recognition system
    if args.interactive:
        try:
            face_recognition_system = RobustFaceRecognition()
            face_recognition_system.run()
        except Exception as e:
            print(f"Application error: {e}")
        finally:
            cv2.destroyAllWindows()
        return

    if not args.image_path and not args.image_base64:
        print(json.dumps({'success': False, 'message': 'No image provided'}))
        return

    # Load reference faces
    try:
        reference_faces, reference_names, reference_student_ids = load_reference_faces()
        if not reference_faces:
            print(json.dumps({'success': False, 'message': 'No reference faces loaded'}))
            return
    except Exception as e:
        print(json.dumps({'success': False, 'message': f'Error loading reference photos: {str(e)}'}))
        return

    # Prepare image file
    image_path = args.image_path
    tmp_file = None
    if not image_path:
        data = args.image_base64
        if data.startswith('data:') and 'base64,' in data:
            data = data.split('base64,', 1)[1]
        raw = base64.b64decode(data)
        fd, tmp_file = tempfile.mkstemp(suffix='.png')
        with os.fdopen(fd, 'wb') as f:
            f.write(raw)
        image_path = tmp_file

    # Load image and detect face
    bgr = cv2.imread(image_path)
    if bgr is None:
        print(json.dumps({'success': False, 'message': 'Invalid image'}))
        if tmp_file:
            os.remove(tmp_file)
        return
    
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
    face_img = detect_face(gray)
    if face_img is None:
        print(json.dumps({'success': False, 'message': 'No face detected'}))
        if tmp_file:
            os.remove(tmp_file)
        return
    
    # Resize face for comparison
    face_resized = cv2.resize(face_img, (100, 100))

    # Compare with reference faces using template matching
    student_id = None
    matched = False
    confidence = 0.0
    best_match = None
    best_similarity = 0

    # Compare with all reference faces
    for i, ref_face in enumerate(reference_faces):
        is_match, similarity = compare_faces(ref_face, face_resized, args.threshold)
        if is_match and similarity > best_similarity:
            best_similarity = similarity
            best_match = reference_names[i]
            student_id = reference_student_ids[i]
            matched = True
            confidence = similarity

    # If expected student provided, enforce identity match
    if args.expected_student_id is not None:
        matched = matched and (student_id == int(args.expected_student_id))

    result = {'success': True, 'matched': matched, 'student_id': student_id, 'confidence': confidence}

    print(json.dumps(result))

    if tmp_file:
        os.remove(tmp_file)


# Main execution
if __name__ == "__main__":
    main()


