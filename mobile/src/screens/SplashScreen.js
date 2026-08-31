import React, { useEffect, useRef } from 'react';
import {
  View,
  Image,
  Text,
  StyleSheet,
  ActivityIndicator,
  Animated,
  StatusBar
} from 'react-native';

/**
 * First-open / data loading: logo → tagline → spinner.
 */
export default function SplashScreen() {
  const fade = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    Animated.timing(fade, {
      toValue: 1,
      duration: 420,
      useNativeDriver: true
    }).start();
  }, [fade]);

  return (
    <View style={styles.root}>
      <StatusBar barStyle="dark-content" backgroundColor="#ffffff" />
      <Animated.View style={[styles.center, { opacity: fade }]}>
        <Image
          source={require('../../assets/logo.png')}
          style={styles.logo}
          resizeMode="contain"
        />
        <View style={styles.vistaBlock}>
          <Text style={styles.vistaName}>VISTA</Text>
          <Text style={styles.vistaLine}>
            Visibility · Inspection · Stock · Trust · Audit
          </Text>
        </View>
        <ActivityIndicator size="small" color="#003580" style={styles.spinner} />
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#ffffff',
    alignItems: 'center',
    justifyContent: 'center'
  },
  center: {
    alignItems: 'center',
    paddingHorizontal: 28
  },
  logo: {
    width: 120,
    height: 120
  },
  vistaBlock: {
    marginTop: 14,
    alignItems: 'center',
    paddingTop: 12,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: '#e2e8f0',
    minWidth: 220
  },
  vistaName: {
    fontSize: 13,
    fontWeight: '700',
    color: '#0f172a',
    letterSpacing: 3,
    textAlign: 'center'
  },
  vistaLine: {
    marginTop: 4,
    fontSize: 10,
    fontWeight: '500',
    color: '#64748b',
    textAlign: 'center',
    lineHeight: 14,
    paddingHorizontal: 8
  },
  spinner: {
    marginTop: 20
  }
});
