import cv2
import os
import numpy as np

# Directory containing reference photos
PHOTOS_DIR = "."

def load_reference_faces():
    """Load reference photos and detect faces in them"""
    reference_faces = []
    reference_names = []
    
    # Load face cascade classifier
    face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
    
    # Supported image extensions
    image_extensions = ['.jpg', '.jpeg', '.png', '.bmp']
    
    print("Loading reference photos...")
    for filename in os.listdir(PHOTOS_DIR):
        if any(filename.lower().endswith(ext) for ext in image_extensions):
            image_path = os.path.join(PHOTOS_DIR, filename)
            
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
                print(f"✓ Loaded reference photo: {filename}")
            else:
                print(f"✗ No face found in: {filename}")
    
    return reference_faces, reference_names

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

def check_face_match(frame, reference_faces, reference_names, face_cascade):
    """Check if any face in the frame matches known faces"""
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(30, 30))
    
    matches_found = []
    
    for (x, y, w, h) in faces:
        # Extract face region
        face_roi = gray[y:y+h, x:x+w]
        face_roi = cv2.resize(face_roi, (100, 100))
        
        best_match = None
        best_similarity = 0
        
        # Compare with all reference faces
        for i, ref_face in enumerate(reference_faces):
            is_match, similarity = compare_faces(ref_face, face_roi)
            if is_match and similarity > best_similarity:
                best_similarity = similarity
                best_match = reference_names[i]
        
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

# Load reference faces
try:
    reference_faces, reference_names = load_reference_faces()
    if not reference_faces:
        print("No reference faces loaded. Please add photos to the directory.")
        exit()
    print(f"Loaded {len(reference_faces)} reference face(s)")
except Exception as e:
    print(f"Error loading reference photos: {e}")
    exit()

# Load face cascade classifier
face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')

# Function to try different camera indices
def find_available_cameras():
    """Find available camera indices"""
    available_cameras = []
    for i in range(5):  # Check first 5 camera indices
        cap = cv2.VideoCapture(i)
        if cap.isOpened():
            available_cameras.append(i)
            cap.release()
    return available_cameras

# Find available cameras
available_cameras = find_available_cameras()
print(f"Available cameras: {available_cameras}")

if not available_cameras:
    print("Error: No cameras found.")
    exit()

# Start with first available camera
current_camera_index = 0
camera_id = available_cameras[current_camera_index]
cap = cv2.VideoCapture(camera_id)

if not cap.isOpened():
    print(f"Error: Could not open camera {camera_id}.")
    exit()

print(f"\nStarting face recognition with camera {camera_id}...")
print("Controls:")
print("- 'q': quit")
print("- 's': capture and check for matches") 
print("- 'c': switch camera")
print("- '1-9': switch to specific camera index")

while True:
    # Read a frame from the webcam
    ret, frame = cap.read()

    if not ret:
        print("Error: Could not read frame.")
        break

    # Check for face matches in real-time
    matches = check_face_match(frame, reference_faces, reference_names, face_cascade)
    
    # Display status
    status_text = "MATCH FOUND!" if matches else "No match"
    status_color = (0, 255, 0) if matches else (0, 0, 255)
    cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, status_color, 2)
    
    if matches:
        match_text = f"Matched: {', '.join(matches)}"
        cv2.putText(frame, match_text, (10, 70), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)

    # Display the frame
    cv2.imshow('Face Recognition - Press Q to quit, S to capture', frame)

    key = cv2.waitKey(1) & 0xFF
    if key == ord('q'):
        break
    elif key == ord('s'):
        # Capture and check
        matches = check_face_match(frame, reference_faces, reference_names, face_cascade)
        if matches:
            print(f"✓ SUCCESS: Face matched with {', '.join(matches)}")
        else:
            print("✗ NO MATCH: Face not recognized")
    elif key == ord('c'):
        # Switch to next available camera
        try:
            cap.release()
            current_camera_index = (current_camera_index + 1) % len(available_cameras)
            camera_id = available_cameras[current_camera_index]
            cap = cv2.VideoCapture(camera_id)
            if cap.isOpened():
                print(f"Switched to camera {camera_id}")
            else:
                print(f"Failed to open camera {camera_id}, trying camera 0")
                cap = cv2.VideoCapture(0)
                camera_id = 0
        except Exception as e:
            print(f"Error switching camera: {e}")
            cap = cv2.VideoCapture(0)
            camera_id = 0
    elif key >= ord('1') and key <= ord('9'):
        # Switch to specific camera index
        try:
            requested_camera = int(chr(key))
            if requested_camera in available_cameras:
                cap.release()
                new_cap = cv2.VideoCapture(requested_camera)
                if new_cap.isOpened():
                    cap = new_cap
                    camera_id = requested_camera
                    print(f"Switched to camera {camera_id}")
                else:
                    new_cap.release()
                    print(f"Camera {requested_camera} failed to open")
            else:
                print(f"Camera {requested_camera} not available")
        except Exception as e:
            print(f"Error switching to camera: {e}")

# Release the webcam and destroy all OpenCV windows
cap.release()
cv2.destroyAllWindows()
