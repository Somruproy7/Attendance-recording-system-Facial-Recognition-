import cv2
import os
import numpy as np
import time
import threading

# Directory containing reference photos
PHOTOS_DIR = "."

class RobustFaceRecognition:
    def __init__(self):
        self.reference_faces = []
        self.reference_names = []
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
                        print(f"✓ Loaded reference photo: {filename}")
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

# Main execution
if __name__ == "__main__":
    try:
        face_recognition_system = RobustFaceRecognition()
        face_recognition_system.run()
    except Exception as e:
        print(f"Application error: {e}")
    finally:
        cv2.destroyAllWindows()