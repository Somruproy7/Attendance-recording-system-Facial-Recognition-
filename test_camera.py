import cv2
import time

def test_camera():
    print("Testing camera access...")
    
    # Test different camera indices
    for i in range(5):
        print(f"\nTesting camera {i}...")
        cap = cv2.VideoCapture(i)
        
        if cap.isOpened():
            print(f"Camera {i} opened successfully")
            
            # Try to read a frame
            ret, frame = cap.read()
            if ret:
                print(f"Frame read successfully from camera {i}")
                print(f"Frame shape: {frame.shape}")
                
                # Display the frame
                cv2.imshow(f'Test Camera {i} - Press Q to quit', frame)
                print("Frame window should be visible. Press 'q' to close.")
                
                # Wait for key press
                key = cv2.waitKey(5000) & 0xFF  # Wait 5 seconds or until key press
                if key == ord('q'):
                    print("User pressed 'q'")
                
                cv2.destroyAllWindows()
                cap.release()
                return i  # Return working camera index
            else:
                print(f"Failed to read frame from camera {i}")
        else:
            print(f"Failed to open camera {i}")
        
        cap.release()
    
    print("No working cameras found")
    return None

if __name__ == "__main__":
    working_camera = test_camera()
    if working_camera is not None:
        print(f"\nWorking camera found at index: {working_camera}")
    else:
        print("\nNo cameras are working. Please check:")
        print("1. Camera is connected properly")
        print("2. Camera drivers are installed")
        print("3. Camera is not being used by another application")
        print("4. OpenCV is installed correctly")
